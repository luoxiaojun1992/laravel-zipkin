<?php

namespace Lxj\Laravel\Zipkin;

use Illuminate\Http\Response;
use Illuminate\Routing\Route;
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
    private function needSample($request)
    {
        $path = $request->getPathInfo();
        $apiPrefix = explode(',', config('zipkin.api_prefix', '/'));
        foreach ($apiPrefix as $prefix) {
            if (stripos($path, $prefix) === 0) {
                return true;
            }
        }

        return false;
    }

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
        if (!$this->needSample($request)) {
            return $next($request);
        }

        /** @var Tracer $laravelTracer */
        $laravelTracer = app(Tracer::class);
        $path = $request->getPathInfo();

        return $laravelTracer->serverSpan($laravelTracer->formatHttpPath($path), function (Span $span) use ($next, $request, $laravelTracer, $path) {
            if ($span->getContext()->isSampled()) {
                $laravelTracer->addTag($span, HTTP_HOST, $request->getHttpHost());
                $laravelTracer->addTag($span, HTTP_PATH, $path);
                $laravelTracer->addTag($span, Tracer::HTTP_QUERY_STRING, (string)$request->getQueryString());
                $laravelTracer->addTag($span, HTTP_METHOD, $request->getMethod());
                $httpRequestBody = $laravelTracer->convertToStr($request->getContent());
                $httpRequestBodyLen = strlen($httpRequestBody);
                $laravelTracer->addTag($span, Tracer::HTTP_REQUEST_BODY_SIZE, $httpRequestBodyLen);
                $laravelTracer->addTag($span, Tracer::HTTP_REQUEST_BODY, $laravelTracer->formatHttpBody(
                    $httpRequestBody,
                    $httpRequestBodyLen
                ));
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
                $route = $request->route();
                if ($route instanceof Route) {
                    $span->setName($request->route()->uri());
                } elseif (is_string($route)) {
                    $span->setName($route);
                }
                if ($response) {
                    if ($span->getContext()->isSampled()) {
                        $laravelTracer->addTag($span, HTTP_STATUS_CODE, $response->getStatusCode());
                        $httpResponseBody = $laravelTracer->convertToStr($response->getContent());
                        $httpResponseBodyLen = strlen($httpResponseBody);
                        $laravelTracer->addTag($span, Tracer::HTTP_RESPONSE_BODY_SIZE, $httpResponseBodyLen);
                        $laravelTracer->addTag($span, Tracer::HTTP_RESPONSE_BODY, $laravelTracer->formatHttpBody(
                            $httpResponseBody,
                            $httpResponseBodyLen
                        ));
                        $laravelTracer->addTag($span, Tracer::HTTP_RESPONSE_HEADERS, json_encode($response->headers->all(), JSON_UNESCAPED_UNICODE));
                        $laravelTracer->addTag(
                            $span,
                            Tracer::HTTP_RESPONSE_PROTOCOL_VERSION,
                            $laravelTracer->formatHttpProtocolVersion($response->getProtocolVersion())
                        );
                    }
                }
            }
        }, true);
    }
}
