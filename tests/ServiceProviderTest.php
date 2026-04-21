<?php

use Illuminate\Filesystem\FilesystemAdapter;

it('registers the pcloud filesystem driver', function () {
    config()->set('filesystems.disks.pcloud', [
        'driver' => 'pcloud',
        'access_token' => 'test-token',
        'location_id' => 1,
        'root' => '/',
    ]);

    $disk = Storage::disk('pcloud');

    expect($disk)->toBeInstanceOf(FilesystemAdapter::class);
});

it('passes the root option to the adapter', function () {
    config()->set('filesystems.disks.pcloud-sub', [
        'driver' => 'pcloud',
        'access_token' => 'test-token',
        'location_id' => 2,
        'root' => '/MyApp',
    ]);

    // Resolving the disk should not throw; the root is applied internally
    $disk = Storage::disk('pcloud-sub');
    expect($disk)->toBeInstanceOf(FilesystemAdapter::class);
});
