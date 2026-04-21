<?php

namespace Leobsst\LaravelPcloudFilesystem;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\FilesystemAdapter as LaravelFilesystemAdapter;
use League\Flysystem\Filesystem;
use pCloud\Sdk\App;
use pCloud\Sdk\Request;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaravelPcloudFilesystemServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-pcloud-filesystem')
            ->hasConfigFile();
    }

    public function bootingPackage(): void
    {
        $this->app->make('filesystem')->extend('pcloud', function (Application $app, array $config): LaravelFilesystemAdapter {
            $pcloudApp = new App;
            $pcloudApp->setAccessToken($config['access_token'] ?? '');
            $pcloudApp->setLocationId($config['location_id'] ?? 1);

            $adapter = new PcloudAdapter(new Request($pcloudApp), $config['root'] ?? '/');
            $filesystem = new Filesystem($adapter);

            return new LaravelFilesystemAdapter($filesystem, $adapter, $config);
        });
    }
}
