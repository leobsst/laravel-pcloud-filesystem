<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Leobsst\LaravelPcloudFilesystem\Http\Controllers\ProxyController;
use Leobsst\LaravelPcloudFilesystem\Support\DiskConfig;

foreach (config('filesystems.disks', []) as $diskName => $diskConfig) {
    if (! DiskConfig::fromConfig($diskName, $diskConfig)->canDefineProxyRoute()) {
        continue;
    }

    $slug = str($diskName)->slug()->toString();

    Route::get('/assets/' . $slug . '/{path}', ProxyController::class)
        ->where('path', '.*')
        ->defaults('disk', $diskName)
        ->name('pcloud-filesystem.' . $slug);
}
