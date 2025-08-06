<?php

namespace AlexMacArthur\LaravelLokiLogging;

use Illuminate\Support\ServiceProvider;

class L3ServiceProvider extends ServiceProvider
{
    public const LOG_LOCATION = 'logs/loki.log';

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/l3.php' => config_path('l3.php'),
        ], 'laravel-loki-logging');
        $this->commands([
            L3Persister::class,
        ]);
    }

    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/l3.php',
            'l3'
        );
    }
}
