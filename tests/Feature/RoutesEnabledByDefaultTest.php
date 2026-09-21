<?php

namespace Sopamo\LaravelFilepond\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;
use Sopamo\LaravelFilepond\LaravelFilepondServiceProvider;

class RoutesEnabledByDefaultTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LaravelFilepondServiceProvider::class];
    }

    public function test_version_two_routes_remain_registered_by_default(): void
    {
        $this->assertTrue(config('filepond.routes_enabled'));

        $this->assertTrue(Route::has('filepond.upload'));
        $this->assertTrue(Route::has('filepond.chunk'));
        $this->assertTrue(Route::has('filepond.delete'));
    }
}
