<?php

namespace Sopamo\LaravelFilepond;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;

class LaravelFilepondServiceProvider extends ServiceProvider
{
    public function boot() {
        $this->registerRoutes();
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
            'prefix' => 'filepond',
            'namespace' => 'Sopamo\LaravelFilepond\Http\Controllers',
            'middleware' => config('filepond.middleware', null),
        ], function () {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
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
