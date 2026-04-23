<?php

declare(strict_types=1);

namespace Leobsst\LaravelPcloudFilesystem;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use Leobsst\LaravelPcloudFilesystem\Commands\ConfigureCommand;
use Leobsst\LaravelPcloudFilesystem\Support\EnvWriter;
use Leobsst\LaravelPcloudFilesystem\Support\PcloudOAuthClient;
use pCloud\Sdk\App;
use pCloud\Sdk\Request;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaravelPcloudFilesystemServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-pcloud-filesystem')
            ->hasCommand(ConfigureCommand::class)
            ->hasInstallCommand(function (InstallCommand $command) {
                $command
                    ->startWith(fn (InstallCommand $cmd) => $cmd->call('pcloud-filesystem:configure'))
                    ->askToStarRepoOnGitHub('leobsst/laravel-pcloud-filesystem');
            });
    }

    public function registeringPackage(): void
    {
        $this->app->singleton(PcloudOAuthClient::class);
        $this->app->singleton(EnvWriter::class);
    }

    public function bootingPackage(): void
    {
        Storage::extend('pcloud', function (Application $app, array $config): FilesystemAdapter {
            $pcloudApp = new App;
            $pcloudApp->setAccessToken($config['access_token'] ?? '');
            $pcloudApp->setLocationId($config['location_id'] ?? 1);

            $adapter = new PcloudAdapter(new Request($pcloudApp), $config['root'] ?? '/');
            $filesystem = new Filesystem($adapter);

            return new FilesystemAdapter($filesystem, $adapter, $config);
        });
    }
}
