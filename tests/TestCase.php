<?php

namespace Sopamo\LaravelFilepond\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Sopamo\LaravelFilepond\LaravelFilepondServiceProvider;

class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            LaravelFilepondServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app->useStoragePath(sys_get_temp_dir().'/laravel-filepond-tests-'.getmypid());
        $app['config']->set('cache.default', 'array');
        $app['config']->set('filesystems.default', 'local');
        $app['config']->set('filepond.routes_enabled', true);
    }
}
