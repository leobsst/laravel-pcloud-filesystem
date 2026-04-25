<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Leobsst\LaravelPcloudFilesystem\Support\DiskConfig;

foreach (config('filesystems.disks', []) as $diskName => $diskConfig) {
    if (! DiskConfig::fromConfig($diskName, $diskConfig)->canDefineProxyRoute()) {
        continue;
    }

    Route::get(
        '/assets/' . str($diskName)->slug()->toString() . '/{path}',
        fn (string $path) => Storage::disk($diskName)->response($path)
    )->where('path', '.*');
}
