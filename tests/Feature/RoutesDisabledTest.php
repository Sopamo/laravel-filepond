<?php

namespace Sopamo\LaravelFilepond\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;
use Sopamo\LaravelFilepond\LaravelFilepondServiceProvider;

class RoutesDisabledTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LaravelFilepondServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('filepond.routes_enabled', false);
    }

    public function test_package_routes_are_not_registered_until_explicitly_enabled(): void
    {
        $this->assertFalse(config('filepond.routes_enabled'));

        $this->assertFalse(Route::has('filepond.upload'));
        $this->assertFalse(Route::has('filepond.chunk'));
        $this->assertFalse(Route::has('filepond.delete'));
    }
}
