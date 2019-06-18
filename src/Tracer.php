<?php

namespace Lxj\Laravel\Zipkin;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\RequestInterface;
use Zipkin\Endpoint;
use const Zipkin\Kind\CLIENT;
use const Zipkin\Kind\SERVER;
use Zipkin\Propagation\DefaultSamplingFlags;
use Zipkin\Propagation\RequestHeaders;
use Zipkin\Propagation\TraceContext;
use Zipkin\Reporters\Http;
use Zipkin\Samplers\BinarySampler;
use Zipkin\Span;
use const Zipkin\Tags\ERROR;
use Zipkin\Tracing;
use Zipkin\TracingBuilder;

/**
 * Class Tracer
 * @package Lxj\Laravel\Zipkin
 */
class Tracer
{
    const HTTP_REQUEST_BODY = 'http.request.body';
    const HTTP_REQUEST_BODY_SIZE = 'http.request.body.size';
    const HTTP_REQUEST_HEADERS = 'http.request.headers';
    const HTTP_REQUEST_PROTOCOL_VERSION = 'http.request.protocol.version';
    const HTTP_REQUEST_SCHEME = 'http.request.scheme';
    const HTTP_RESPONSE_BODY = 'http.response.body';
    const HTTP_RESPONSE_BODY_SIZE = 'http.response.body.size';
    const HTTP_RESPONSE_HEADERS = 'http.response.headers';
    const HTTP_RESPONSE_PROTOCOL_VERSION = 'http.response.protocol.version';
    const RUNTIME_START_SYSTEM_LOAD = 'runtime.start_system_load';
    const RUNTIME_FINISH_SYSTEM_LOAD = 'runtime.finish_system_load';
    const RUNTIME_MEMORY = 'runtime.memory';
    const RUNTIME_PHP_VERSION = 'runtime.php.version';
    const RUNTIME_PHP_SAPI = 'runtime.php.sapi';
    const DB_QUERY_TIMES = 'db.query.times';
    const DB_QUERY_TOTAL_DURATION = 'db.query.total.duration';
    const REDIS_EXEC_TIMES = 'redis.exec.times';
    const REDIS_EXEC_TOTAL_DURATION = 'redis.exec.total.duration';
    const FRAMEWORK_VERSION = 'framework.version';
    const HTTP_QUERY_STRING = 'http.query_string';

    private $serviceName = 'laravel-zipkin';
    private $endpointUrl = 'http://localhost:9411/api/v2/spans';
    private $sampleRate = 0;
    private $bodySize = 5000;
    private $curlTimeout = 1;
    private $redisOptions = [
        'queue_name' => 'queue:zipkin:span',
        'connection' => 'zipkin',
    ];
    private $reportType = 'http';

    /** @var \Zipkin\Tracer */
    private $tracer;

    /** @var Tracing */
    private $tracing;

    /** @var array TraceContext[] */
    private $contextStack = [];

    //DB metrics
    private $dbQueryTimes = [];
    private $totalDbQueryDuration = [];

    //Redis metrics
    private $redisExecTimes = [];
    private $totalRedisExecDuration = [];

    /**
     * Tracer constructor.
     * @param $config
     */
    public function __construct($config)
    {
        $this->serviceName = isset($config['service_name']) ? $config['service_name'] : 'laravel-zipkin';
        $this->endpointUrl = isset($config['endpoint_url']) ? $config['endpoint_url'] : 'http://localhost:9411/api/v2/spans';
        $this->sampleRate = isset($config['sample_rate']) ? $config['sample_rate'] : 0;
        $this->bodySize = isset($config['body_size']) ? $config['body_size'] : 5000;
        $this->curlTimeout = isset($config['curl_timeout']) ? $config['curl_timeout'] : 1;
        $redisOptions = isset($config['redis_options']) ? $config['redis_options'] : [];
        $this->redisOptions = array_merge($this->redisOptions, $redisOptions);
        $this->reportType = isset($config['report_type']) ? $config['report_type'] : 'http';

        $this->createTracer();

        $this->listenDbQuery();

        $this->listenRedisQuery();
    }

    /**
     * Create zipkin tracer
     */
    private function createTracer()
    {
        if (!\App::runningInConsole()) {
            $remotePort = \Illuminate\Support\Facades\Request::instance()->server('REMOTE_PORT');
            $endpoint = Endpoint::create(
                $this->serviceName,
                \Illuminate\Support\Facades\Request::ip(),
                null,
                $remotePort ? (int)$remotePort : null
            );
        } else {
            $endpoint = Endpoint::create($this->serviceName);
        }
        $sampler = BinarySampler::createAsAlwaysSample();
        $this->tracing = TracingBuilder::create()
            ->havingLocalEndpoint($endpoint)
            ->havingSampler($sampler)
            ->havingReporter($this->getReporter())
            ->build();
        $this->tracer = $this->getTracing()->getTracer();
    }

    private function getReporter()
    {
        if ($this->reportType === 'redis') {
            return new RedisReporter($this->redisOptions);
        } elseif ($this->reportType === 'http') {
            return new Http(null, ['endpoint_url' => $this->endpointUrl, 'timeout' => $this->curlTimeout]);
        }

        return new Http(null, ['endpoint_url' => $this->endpointUrl, 'timeout' => $this->curlTimeout]);
    }

    /**
     * Listen db query event
     */
    private function listenDbQuery()
    {
        \Event::listen(QueryExecuted::class, function (QueryExecuted $event) {
            $identify = $event->connection->getDriverName() . '.' . $event->connectionName;
            if (isset($this->dbQueryTimes[$identify])) {
                $this->dbQueryTimes[$identify]++;
            } else {
                $this->dbQueryTimes[$identify] = 1;
            }
            if (isset($this->totalDbQueryDuration[$identify])) {
                $this->totalDbQueryDuration[$identify] += $event->time;
            } else {
                $this->totalDbQueryDuration[$identify] = $event->time;
            }
        });
    }

    /**
     * Listen redis query event
     */
    private function listenRedisQuery()
    {
        \Event::listen(CommandExecuted::class, function (CommandExecuted $event) {
            $identify = $event->connectionName;
            if (isset($this->redisExecTimes[$identify])) {
                $this->redisExecTimes[$identify]++;
            } else {
                $this->redisExecTimes[$identify] = 1;
            }
            if (isset($this->totalRedisExecDuration[$identify])) {
                $this->totalRedisExecDuration[$identify] += $event->time;
            } else {
                $this->totalRedisExecDuration[$identify] = $event->time;
            }
        });
    }

    /**
     * @return Tracing
     */
    public function getTracing()
    {
        return $this->tracing;
    }

    /**
     * @return \Zipkin\Tracer
     */
    public function getTracer()
    {
        return $this->tracer;
    }

    /**
     * Create a server trace
     *
     * @param $name
     * @param $callback
     * @param bool $flush
     * @return mixed
     * @throws \Exception
     */
    public function serverSpan($name, $callback, $flush = false)
    {
        return $this->span($name, $callback, SERVER, $flush);
    }

    /**
     * Create a client trace
     *
     * @param $name
     * @param $callback
     * @param bool $flush
     * @return mixed
     * @throws \Exception
     */
    public function clientSpan($name, $callback, $flush = false)
    {
        return $this->span($name, $callback, CLIENT, $flush);
    }

    /**
     * Create a trace
     *
     * @param string $name
     * @param callable $callback
     * @param null|string $kind
     * @param bool $flush
     * @return mixed
     * @throws \Exception
     */
    public function span($name, $callback, $kind = null, $flush = false)
    {
        $parentContext = $this->getParentContext();
        $span = $this->getSpan($parentContext);
        $span->setName($name);
        if ($kind) {
            $span->setKind($kind);
        }

        $span->start();

        $spanContext = $span->getContext();
        array_push($this->contextStack, $spanContext);

        $startDbQueryTimes = $this->dbQueryTimes;
        $startDbQueryDuration = $this->totalDbQueryDuration;
        $startRedisExecTimes = $this->redisExecTimes;
        $startRedisExecDuration = $this->totalRedisExecDuration;
        $startMemory = 0;
        if ($span->getContext()->isSampled()) {
            $startMemory = memory_get_usage();
            $this->beforeSpanTags($span);
        }

        try {
            return call_user_func_array($callback, ['span' => $span]);
        } catch (\Exception $e) {
            if ($span->getContext()->isSampled()) {
                $this->addTag($span, ERROR, $e->getMessage() . PHP_EOL . $e->getTraceAsString());
            }
            throw $e;
        } finally {
            if ($span->getContext()->isSampled()) {
                foreach ($this->dbQueryTimes as $identify => $value) {
                    $this->addTag($span, self::DB_QUERY_TIMES . '.' . $identify, $value - (isset($startDbQueryTimes[$identify]) ? $startDbQueryTimes[$identify] : 0));
                }
                foreach ($this->totalDbQueryDuration as $identify => $value) {
                    $this->addTag($span, self::DB_QUERY_TOTAL_DURATION . '.' . $identify, ($value - (isset($startDbQueryDuration[$identify]) ? $startDbQueryDuration[$identify] : 0)) . 'ms');
                }
                foreach ($this->redisExecTimes as $identify => $value) {
                    $this->addTag($span, self::REDIS_EXEC_TIMES . '.' . $identify, $value - (isset($startRedisExecTimes[$identify]) ? $startRedisExecTimes[$identify] : 0));
                }
                foreach ($this->totalRedisExecDuration as $identify => $value) {
                    $this->addTag($span, self::REDIS_EXEC_TOTAL_DURATION . '.' . $identify, ($value - (isset($startRedisExecDuration[$identify]) ? $startRedisExecDuration[$identify] : 0)) . 'ms');
                }
                $this->addTag($span, static::RUNTIME_MEMORY, round((memory_get_usage() - $startMemory) / 1000000, 2) . 'MB');
                $this->afterSpanTags($span);
            }

            $span->finish();
            array_pop($this->contextStack);

            if ($flush) {
                $this->flushTracer();
            }
        }
    }

    /**
     * Formatting http protocol version
     *
     * @param $protocolVersion
     * @return string
     */
    public function formatHttpProtocolVersion($protocolVersion)
    {
        if (stripos($protocolVersion, 'HTTP/') !== 0) {
            return 'HTTP/' . $protocolVersion;
        }

        return strtoupper($protocolVersion);
    }

    /**
     * Formatting http body
     *
     * @param $httpBody
     * @param null $bodySize
     * @return string
     */
    public function formatHttpBody($httpBody, $bodySize = null)
    {
        $httpBody = $this->convertToStr($httpBody);

        if (is_null($bodySize)) {
            $bodySize = strlen($httpBody);
        }

        if ($bodySize > $this->bodySize) {
            $httpBody = mb_substr($httpBody, 0, $this->bodySize, 'utf8') . ' ...';
        }

        return $httpBody;
    }

    /**
     * Formatting http path
     *
     * @param $httpPath
     * @return string|string[]|null
     */
    public function formatHttpPath($httpPath)
    {
        $httpPath = preg_replace('/\/\d+$/', '/{id}', $httpPath);
        $httpPath = preg_replace('/\/\d+\//', '/{id}/', $httpPath);

        return $httpPath;
    }

    /**
     * Formatting route path
     *
     * @param $route
     * @return string
     */
    public function formatRoutePath($route)
    {
        if (strpos($route, '/') !== 0) {
            $route = '/' . $route;
        }

        return $route;
    }

    /**
     * Add span tag
     *
     * @param Span $span
     * @param $key
     * @param $value
     */
    public function addTag($span, $key, $value)
    {
        $span->tag($key, $this->convertToStr($value));
    }

    /**
     * Convert variable to string
     *
     * @param $value
     * @return string
     */
    public function convertToStr($value)
    {
        if (!is_scalar($value)) {
            $value = '';
        } else {
            $value = (string)$value;
        }

        return $value;
    }

    /**
     * Inject trace context to psr request
     *
     * @param TraceContext $context
     * @param RequestInterface $request
     */
    public function injectContextToRequest($context, &$request)
    {
        $injector = $this->getTracing()->getPropagation()->getInjector(new RequestHeaders());
        $injector($context, $request);
    }

    /**
     * Extract trace context from laravel request
     *
     * @param Request $request
     * @return TraceContext|DefaultSamplingFlags
     */
    public function extractRequestToContext($request)
    {
        $extractor = $this->getTracing()->getPropagation()->getExtractor(new LaravelRequestHeaders());
        return $extractor($request);
    }

    /**
     * @return TraceContext|DefaultSamplingFlags|null
     */
    private function getParentContext()
    {
        $parentContext = null;
        $contextStackLen = count($this->contextStack);
        if ($contextStackLen > 0) {
            $parentContext = $this->contextStack[$contextStackLen - 1];
        } else {
            if (!\App::runningInConsole()) {
                //Extract trace context from laravel request
                $parentContext = $this->extractRequestToContext(\Illuminate\Support\Facades\Request::instance());
            }
        }

        return $parentContext;
    }

    /**
     * @param TraceContext|DefaultSamplingFlags $parentContext
     * @return \Zipkin\Span
     */
    private function getSpan($parentContext)
    {
        $tracer = $this->getTracer();

        if (!$parentContext) {
            $span = $tracer->newTrace($this->getDefaultSamplingFlags());
        } else {
            if ($parentContext instanceof TraceContext) {
                $span = $tracer->newChild($parentContext);
            } else {
                if (is_null($parentContext->isSampled())) {
                    $samplingFlags = $this->getDefaultSamplingFlags();
                } else {
                    $samplingFlags = $parentContext;
                }

                $span = $tracer->newTrace($samplingFlags);
            }
        }

        return $span;
    }

    /**
     * @return DefaultSamplingFlags
     */
    private function getDefaultSamplingFlags()
    {
        $sampleRate = $this->sampleRate;
        if ($sampleRate >= 1) {
            $samplingFlags = DefaultSamplingFlags::createAsEmpty(); //Sample config determined by sampler
        } elseif ($sampleRate <= 0) {
            $samplingFlags = DefaultSamplingFlags::createAsNotSampled();
        } else {
            mt_srand(time());
            if (mt_rand() / mt_getrandmax() <= $sampleRate) {
                $samplingFlags = DefaultSamplingFlags::createAsEmpty(); //Sample config determined by sampler
            } else {
                $samplingFlags = DefaultSamplingFlags::createAsNotSampled();
            }
        }

        return $samplingFlags;
    }

    /**
     * @param Span $span
     */
    private function startSysLoadTag($span)
    {
        //Not supported in windows os
        if (!function_exists('sys_getloadavg')) {
            return;
        }

        $startSystemLoad = sys_getloadavg();
        foreach ($startSystemLoad as $k => $v) {
            $startSystemLoad[$k] = round($v, 2);
        }
        $this->addTag($span, static::RUNTIME_START_SYSTEM_LOAD, implode(',', $startSystemLoad));
    }

    /**
     * @param Span $span
     */
    private function finishSysLoadTag($span)
    {
        //Not supported in windows os
        if (!function_exists('sys_getloadavg')) {
            return;
        }

        $finishSystemLoad = sys_getloadavg();
        foreach ($finishSystemLoad as $k => $v) {
            $finishSystemLoad[$k] = round($v, 2);
        }
        $this->addTag($span, static::RUNTIME_FINISH_SYSTEM_LOAD, implode(',', $finishSystemLoad));
    }

    /**
     * @param Span $span
     */
    private function beforeSpanTags($span)
    {
        $this->addTag($span, self::FRAMEWORK_VERSION, 'Laravel-' . \App::version());
        $this->addTag($span, self::RUNTIME_PHP_VERSION, PHP_VERSION);
        $this->addTag($span, self::RUNTIME_PHP_SAPI, php_sapi_name());

        $this->startSysLoadTag($span);
    }

    /**
     * @param Span $span
     */
    private function afterSpanTags($span)
    {
        $this->finishSysLoadTag($span);
    }

    private function flushTracer()
    {
        try {
            if ($tracer = $this->getTracer()) {
                $tracer->flush();
            }
        } catch (\Exception $e) {
            Log::error('Zipkin report error ' . $e->getMessage());
        }
    }

    public function __destruct()
    {
        $this->flushTracer();
    }
}
