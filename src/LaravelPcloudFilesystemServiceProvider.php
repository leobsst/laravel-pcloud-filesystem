<?php

declare(strict_types=1);

namespace Leobsst\LaravelPcloudFilesystem;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use Leobsst\LaravelPcloudFilesystem\Commands\ConfigureCommand;
use Leobsst\LaravelPcloudFilesystem\Enums\LocationEnum;
use Leobsst\LaravelPcloudFilesystem\Support\DiskConfig;
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
            ->hasRoute('web')
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
        // For each pcloud disk that has no url configured, auto-inject the proxy URL that
        // routes/web.php registers. This happens before the factory is called so that
        // FilesystemAdapter picks up the url when Storage::disk() is first resolved.
        foreach (config('filesystems.disks', []) as $diskName => $diskConfig) {
            $config = DiskConfig::fromConfig($diskName, $diskConfig);

            if (! $config->isPcloudDisk() || $config->hasUrl()) {
                continue;
            }

            config(["filesystems.disks.{$diskName}.url" => '/assets/' . str($diskName)->slug()->toString()]);
        }

        Storage::extend('pcloud', function (Application $app, array $config): FilesystemAdapter {
            $pcloudApp = new App;
            $location = DiskConfig::parseLocation($config['location_id'] ?? LocationEnum::US);
            $pcloudApp->setAccessToken($config['access_token'] ?? '');
            $pcloudApp->setLocationId($location->value);

            $adapter = new PcloudAdapter(new Request($pcloudApp), $config['root'] ?? '/');
            $filesystem = new Filesystem($adapter);

            return new FilesystemAdapter($filesystem, $adapter, $config);
        });
    }
}
