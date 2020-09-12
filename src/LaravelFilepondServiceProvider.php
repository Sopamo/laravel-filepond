<?php

namespace Sopamo\LaravelFilepond;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class LaravelFilepondServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->registerRoutes();
        $this->publishes([
            $this->getConfigFile() => config_path('filepond.php'),
        ], 'filepond');
    }

    /**
     * {@inheritdoc}
     */
    public function register()
    {
        $this->mergeConfigFrom(
            $this->getConfigFile(),
            'filepond'
        );
    }

    /**
     * Register Filepond routes.
     *
     * @return void
     */
    protected function registerRoutes()
    {
        Route::group([
            'prefix' => config('filepond.route_prefix', 'filepond'),
            'middleware' => config('filepond.middleware', null),
        ], function () {
            $this->loadRoutesFrom(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR . 'web.php');
        });
    }

    /**
     * @return string
     */
    protected function getConfigFile(): string
    {
        return __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'filepond.php';
    }
}
