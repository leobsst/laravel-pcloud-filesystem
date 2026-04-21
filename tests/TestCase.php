<?php

namespace Leobsst\LaravelPcloudFilesystem\Tests;

use Leobsst\LaravelPcloudFilesystem\LaravelPcloudFilesystemServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function getPackageProviders($app)
    {
        return [
            LaravelPcloudFilesystemServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');
    }
}
