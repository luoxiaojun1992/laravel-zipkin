<?php

namespace Lxj\Laravel\Zipkin;

use Illuminate\Support\ServiceProvider;

class Provider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot()
    {
        $this->publishes([__DIR__ . '/config/zipkin.php' => config_path('zipkin.php')], 'config');

        $this->commands(ZipkinReporter::class);
    }
}
