<?php

namespace Lxj\Laravel\Zipkin;

use GuzzleHttp\Client as GuzzleHttpClient;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\RequestInterface;
use Zipkin\Span;
use const Zipkin\Tags\HTTP_HOST;
use const Zipkin\Tags\HTTP_METHOD;
use const Zipkin\Tags\HTTP_PATH;
use const Zipkin\Tags\HTTP_STATUS_CODE;

/**
 * Class HttpClient
 * @package Lxj\Laravel\Zipkin
 */
class HttpClient extends GuzzleHttpClient
{
    /**
     * Send http request with zipkin trace
     *
     * @param RequestInterface $request
     * @param array $options
     * @return mixed|\Psr\Http\Message\ResponseInterface|null
     * @throws \Exception
     */
    public function send(RequestInterface $request, array $options = [])
    {
        $laravelTracer = app(Tracer::class);
        $path = $request->getUri()->getPath();

        return $laravelTracer->span('Call api:' . $path, function (Span $span) use ($request, $options, $laravelTracer, $path) {
            //Inject trace context to api psr request
            $laravelTracer->injectContextToRequest($span->getContext(), $request);

            if ($span->getContext()->isSampled()) {
                $laravelTracer->addTag($span, HTTP_HOST, $request->getUri()->getHost());
                $laravelTracer->addTag($span, HTTP_PATH, $path);
                $laravelTracer->addTag($span, Tracer::HTTP_QUERY_STRING, (string)$request->getUri()->getQuery());
                $laravelTracer->addTag($span, HTTP_METHOD, $request->getMethod());
                $laravelTracer->addTag($span, Tracer::HTTP_REQUEST_BODY, $laravelTracer->formatHttpBody($request->getBody()->getContents(), $request->getBody()->getSize()));
                $request->getBody()->seek(0);
                $laravelTracer->addTag($span, Tracer::HTTP_REQUEST_HEADERS, json_encode($request->getHeaders(), JSON_UNESCAPED_UNICODE));
                $laravelTracer->addTag(
                    $span,
                    Tracer::HTTP_REQUEST_PROTOCOL_VERSION,
                    $laravelTracer->formatHttpProtocolVersion($request->getProtocolVersion())
                );
                $laravelTracer->addTag($span, Tracer::HTTP_REQUEST_SCHEME, $request->getUri()->getScheme());
            }

            $response = null;
            try {
                $response = parent::send($request, $options);
                return $response;
            } catch (\Exception $e) {
                Log::error('CURL ERROR ' . $e->getMessage());
                throw new \Exception('CURL ERROR ' . $e->getMessage());
            } finally {
                if ($response) {
                    if ($span->getContext()->isSampled()) {
                        $laravelTracer->addTag($span, HTTP_STATUS_CODE, $response->getStatusCode());
                        $laravelTracer->addTag($span, Tracer::HTTP_RESPONSE_BODY, $laravelTracer->formatHttpBody($response->getBody()->getContents(), $response->getBody()->getSize()));
                        $response->getBody()->seek(0);
                        $laravelTracer->addTag($span, Tracer::HTTP_RESPONSE_HEADERS, json_encode($response->getHeaders(), JSON_UNESCAPED_UNICODE));
                        $laravelTracer->addTag(
                            $span,
                            Tracer::HTTP_RESPONSE_PROTOCOL_VERSION,
                            $laravelTracer->formatHttpProtocolVersion($response->getProtocolVersion())
                        );
                    }
                }
            }
        }, null, \Zipkin\Kind\CLIENT);
    }
}
