<?php

namespace Sopamo\LaravelFilepond;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class LaravelFilepondServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->registerRoutes();
        $this->publishes([
            $this->getConfigFile() => config_path('filepond.php'),
        ], 'filepond');
    }

    /**
     * {@inheritdoc}
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            $this->getConfigFile(),
            'filepond',
        );
    }

    /**
     * Register Filepond routes.
     */
    protected function registerRoutes(): void
    {
        if (!config('filepond.routes_enabled', true)) {
            return;
        }

        Route::group([
            'prefix' => config('filepond.route_prefix', 'filepond'),
            'middleware' => config('filepond.middleware', null),
        ], function () {
            $this->loadRoutesFrom(__DIR__.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'routes'.DIRECTORY_SEPARATOR.'web.php');
        });
    }

    protected function getConfigFile(): string
    {
        return __DIR__.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'filepond.php';
    }
}
