<?php

namespace Sopamo\LaravelFilepond;

use Illuminate\Support\Facades\Config;
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
            'filepond'
        );
    }

    /**
     * Register Filepond routes.
     *
     * @return void
     */
    protected function registerRoutes(): void
    {
        Route::group([
            'prefix' => Config::string('filepond.route_prefix'),
            'middleware' => config('filepond.middleware'),
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
