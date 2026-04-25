<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

foreach (config('filesystems.disks', []) as $diskName => $diskConfig) {
    if (($diskConfig['driver'] ?? '') !== 'pcloud') {
        continue;
    }

    Route::get(
        '/assets/' . str($diskName)->slug()->toString() . '/{path}',
        fn (string $path) => Storage::disk($diskName)->response($path)
    )->where('path', '.*');
}
