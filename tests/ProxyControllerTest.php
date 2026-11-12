<?php

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use League\Flysystem\Filesystem;
use Leobsst\LaravelPcloudFilesystem\Http\Controllers\ProxyController;
use Leobsst\LaravelPcloudFilesystem\PcloudAdapter;
use pCloud\Sdk\Exception as PcloudException;
use pCloud\Sdk\Request;

/**
 * pCloud API double: answers `stat` from a fixed table and counts calls (no network).
 */
class ProxyFakeRequest extends Request
{
    /** @var array<string, stdClass> */
    public array $files = [];

    public int $statCalls = 0;

    public function __construct() {}

    public function get(string $method, ?array $params = []): stdClass
    {
        if ($method === 'stat') {
            $this->statCalls++;

            if (isset($this->files[$params['path']])) {
                $response = new stdClass;
                $response->metadata = $this->files[$params['path']];

                return $response;
            }
        }

        throw new PcloudException('not found');
    }
}

function proxyDisk(array $config = []): ProxyFakeRequest
{
    $request = new ProxyFakeRequest;
    $file = new stdClass;
    $file->isfolder = false;
    $file->size = 1234;
    $file->contenttype = 'image/jpeg';
    $file->modified = 'Mon, 02 Feb 2026 10:00:00 +0000';
    $file->hash = 'abc123';
    $request->files['/photo.jpg'] = $file;

    $svg = clone $file;
    $svg->contenttype = 'image/svg+xml';
    $request->files['/logo.svg'] = $svg;

    $config = array_merge(['driver' => 'pcloud', 'url' => '/assets/media'], $config);
    config()->set('filesystems.disks.media', $config);

    $adapter = new PcloudAdapter($request, '/', '/assets/media', 'pcloud-media');
    Storage::set('media', new FilesystemAdapter(new Filesystem($adapter), $adapter, $config));

    Route::get('/assets/media/{path}', ProxyController::class)
        ->where('path', '.*')
        ->defaults('disk', 'media')
        ->name('pcloud-media');

    return $request;
}

it('serves a public file with caching headers', function () {
    proxyDisk();

    $this->get('/assets/media/photo.jpg')
        ->assertOk()
        ->assertHeader('ETag', '"abc123"')
        ->assertHeader('Content-Type', 'image/jpeg')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Cache-Control', 'max-age=86400, public');
});

it('answers 304 without downloading when the browser copy is fresh', function () {
    proxyDisk();

    $this->get('/assets/media/photo.jpg', ['If-None-Match' => '"abc123"'])->assertStatus(304);
});

it('caches metadata so repeated views do not call pCloud again', function () {
    $request = proxyDisk();

    $this->get('/assets/media/photo.jpg', ['If-None-Match' => '"abc123"']);
    $this->get('/assets/media/photo.jpg', ['If-None-Match' => '"abc123"']);

    expect($request->statCalls)->toBe(1);
});

it('rejects a tampered or stripped signature', function () {
    proxyDisk();

    $this->get('/assets/media/photo.jpg?expires=' . (time() + 60) . '&signature=forged')->assertForbidden();
    $this->get('/assets/media/photo.jpg?expires=' . (time() + 60))->assertForbidden();
});

it('serves a private disk only through a valid signed URL', function () {
    proxyDisk(['visibility' => 'private']);

    $this->get('/assets/media/photo.jpg')->assertForbidden();
    $this->get(URL::temporarySignedRoute('pcloud-media', now()->addMinute(), ['path' => 'photo.jpg']))->assertOk();
});

it('refuses paths escaping the disk root', function () {
    proxyDisk();

    $this->get('/assets/media/../secret.txt')->assertNotFound();
    $this->get('/assets/media/a/%2E%2E/secret.txt')->assertNotFound();
});

it('forces a download for types that could run scripts', function () {
    proxyDisk();

    $this->get('/assets/media/logo.svg')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/octet-stream');
    expect($this->get('/assets/media/logo.svg')->headers->get('Content-Disposition'))->toStartWith('attachment');
});

it('returns 404 for a missing file', function () {
    proxyDisk();

    $this->get('/assets/media/missing.jpg')->assertNotFound();
});

it('refuses traversal at the adapter level', function () {
    $adapter = new PcloudAdapter(new ProxyFakeRequest, '/site');

    expect(fn () => $adapter->metadata('../other-site/file.jpg'))->toThrow(Exception::class)
        ->and(PcloudAdapter::isSafePath('a/../b'))->toBeFalse()
        ->and(PcloudAdapter::isSafePath('a/b.jpg'))->toBeTrue();
});
