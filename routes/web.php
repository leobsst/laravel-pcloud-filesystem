<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Leobsst\LaravelPcloudFilesystem\Support\DiskConfig;

foreach (config('filesystems.disks', []) as $diskName => $diskConfig) {
    if (! DiskConfig::fromConfig($diskName, $diskConfig)->canDefineProxyRoute()) {
        continue;
    }

    $slug = str($diskName)->slug()->toString();

    Route::get(
        '/assets/' . $slug . '/{path}',
        function (Request $request, string $path) use ($diskName) {
            if ($request->has('expires') && ! $request->hasValidSignature()) {
                abort(403);
            }

            return Storage::disk($diskName)->response($path);
        }
    )
        ->where('path', '.*')
        ->name('pcloud-filesystem.' . $slug);
}
