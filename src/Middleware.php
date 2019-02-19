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
 * @package Jing\Laravel\Zipkin
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
        $path = $request->getRequestUri();
        return $laravelTracer->rootSpan('Server recv:' . $path, function (Span $span) use ($next, $request, $laravelTracer, $path) {
            if ($span->getContext()->isSampled()) {
                $span->tag(HTTP_HOST, $request->getHttpHost());
                $span->tag(HTTP_PATH, $path);
                $span->tag(HTTP_METHOD, $request->getMethod());
                $span->tag(Tracer::HTTP_REQUEST_BODY, is_string($request->getContent()) ? $request->getContent() : '');
                $span->tag(Tracer::HTTP_REQUEST_HEADERS, json_encode($request->headers->all(), JSON_UNESCAPED_UNICODE));
                $span->tag(
                    Tracer::HTTP_REQUEST_PROTOCOL_VERSION,
                    $laravelTracer->formatHttpProtocolVersion($request->getProtocolVersion())
                );
                $span->tag(Tracer::HTTP_REQUEST_SCHEME, $request->getScheme());
            }

            /** @var Response $response */
            $response = null;
            try {
                $response = $next($request);

                if ($span->getContext()->isSampled()) {
                    if ($response->isServerError()) {
                        $span->tag(ERROR, 'server error');
                    } elseif ($response->isClientError()) {
                        $span->tag(ERROR, 'client error');
                    }
                }

                return $response;
            } catch (\Exception $e) {
                throw $e;
            } finally {
                if ($response) {
                    if ($span->getContext()->isSampled()) {
                        $span->tag(HTTP_STATUS_CODE, $response->getStatusCode());
                        $span->tag(Tracer::HTTP_RESPONSE_BODY, $response->getContent());
                        $span->tag(Tracer::HTTP_RESPONSE_HEADERS, json_encode($response->headers->all(), JSON_UNESCAPED_UNICODE));
                        $span->tag(
                            Tracer::HTTP_RESPONSE_PROTOCOL_VERSION,
                            $laravelTracer->formatHttpProtocolVersion($response->getProtocolVersion())
                        );
                    }
                }
            }
        }, null, \Zipkin\Kind\SERVER, true);
    }
}
