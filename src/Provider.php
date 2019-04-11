<?php

namespace Lxj\Laravel\Zipkin;

use Illuminate\Support\ServiceProvider;
use Lxj\Laravel\Zipkin\Commands\ExportWatches;
use Lxj\Laravel\Zipkin\Commands\ImportWatches;
use Lxj\Laravel\Zipkin\Commands\ZipkinReporter;

class Provider extends ServiceProvider
{
    public function register()
    {
        parent::register();

        $this->app->singleton(Tracer::class, function(){
            return new Tracer(config('zipkin'));
        });
    }

    /**
     * Bootstrap the application services.
     */
    public function boot()
    {
        $this->publishes([__DIR__ . '/config/zipkin.php' => config_path('zipkin.php')], 'config');

        $this->commands([
            ZipkinReporter::class,
            ExportWatches::class,
            ImportWatches::class,
        ]);
    }
}
