<?php

namespace Lxj\Laravel\Zipkin;

use Illuminate\Http\Response;
use Zipkin\Span;
use const Zipkin\Tags\ERROR;
use const Zipkin\Tags\HTTP_HOST;
use const Zipkin\Tags\HTTP_METHOD;
use const Zipkin\Tags\HTTP_PATH;
use const Zipkin\Tags\HTTP_STATUS_CODE;

/**
 * Class Middleware
 * @package Lxj\Laravel\Zipkin
 */
class Middleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     * @throws \Exception
     */
    public function handle($request, \Closure $next)
    {
        $laravelTracer = app(Tracer::class);
        $path = $request->getPathInfo();
        return $laravelTracer->rootSpan('Server recv:' . $path, function (Span $span) use ($next, $request, $laravelTracer, $path) {
            if ($span->getContext()->isSampled()) {
                $laravelTracer->addTag($span, HTTP_HOST, $request->getHttpHost());
                $laravelTracer->addTag($span, HTTP_PATH, $path);
                $laravelTracer->addTag($span, Tracer::HTTP_QUERY_STRING, (string)$request->getQueryString());
                $laravelTracer->addTag($span, HTTP_METHOD, $request->getMethod());
                $laravelTracer->addTag($span, Tracer::HTTP_REQUEST_BODY, $laravelTracer->formatHttpBody($request->getContent()));
                $laravelTracer->addTag($span, Tracer::HTTP_REQUEST_HEADERS, json_encode($request->headers->all(), JSON_UNESCAPED_UNICODE));
                $laravelTracer->addTag(
                    $span,
                    Tracer::HTTP_REQUEST_PROTOCOL_VERSION,
                    $laravelTracer->formatHttpProtocolVersion($request->getProtocolVersion())
                );
                $laravelTracer->addTag($span, Tracer::HTTP_REQUEST_SCHEME, $request->getScheme());
            }

            /** @var Response $response */
            $response = null;
            try {
                $response = $next($request);

                if ($span->getContext()->isSampled()) {
                    if ($response->isServerError()) {
                        $laravelTracer->addTag($span, ERROR, 'server error');
                    } elseif ($response->isClientError()) {
                        $laravelTracer->addTag($span, ERROR, 'client error');
                    }
                }

                return $response;
            } catch (\Exception $e) {
                throw $e;
            } finally {
                if ($response) {
                    if ($span->getContext()->isSampled()) {
                        $laravelTracer->addTag($span, HTTP_STATUS_CODE, $response->getStatusCode());
                        $laravelTracer->addTag($span, Tracer::HTTP_RESPONSE_BODY, $laravelTracer->formatHttpBody($response->getContent()));
                        $laravelTracer->addTag($span, Tracer::HTTP_RESPONSE_HEADERS, json_encode($response->headers->all(), JSON_UNESCAPED_UNICODE));
                        $laravelTracer->addTag(
                            $span,
                            Tracer::HTTP_RESPONSE_PROTOCOL_VERSION,
                            $laravelTracer->formatHttpProtocolVersion($response->getProtocolVersion())
                        );
                    }
                }
            }
        }, null, \Zipkin\Kind\SERVER, true);
    }
}
