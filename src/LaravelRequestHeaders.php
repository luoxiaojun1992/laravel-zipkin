<?php

namespace Lxj\Laravel\Zipkin;

use Illuminate\Http\Request;
use Zipkin\Propagation\Getter;
use Zipkin\Propagation\Setter;

final class LaravelRequestHeaders implements Getter, Setter
{
    /**
     * {@inheritdoc}
     *
     * @param Request $carrier
     */
    public function get($carrier, $key)
    {
        $lKey = strtolower($key);
        return $carrier->hasHeader($lKey) ? $carrier->header($lKey) : null;
    }

    /**
     * {@inheritdoc}
     *
     * @param Request $carrier
     * @throws \InvalidArgumentException for invalid header names or values.
     */
    public function put(&$carrier, $key, $value)
    {
        $lKey = strtolower($key);
        $carrier = $carrier->headers->set($lKey, $value, false);
    }
}
