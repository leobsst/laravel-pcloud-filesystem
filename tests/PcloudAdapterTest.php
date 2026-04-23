<?php

use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\Visibility;
use Leobsst\LaravelPcloudFilesystem\PcloudAdapter;
use pCloud\Sdk\Exception as PcloudException;
use pCloud\Sdk\Request;

// ─── Test double ─────────────────────────────────────────────────────────────

/**
 * Stub for pCloud\Sdk\Request that bypasses the App constructor requirement
 * and lets tests queue responses by method + params.
 */
class FakeRequest extends Request
{
    /** @var array<string, stdClass|Throwable> */
    private array $responses = [];

    /** @var array<array{string, array|null}> */
    public array $getCalls = [];

    /** @var array<array{string, string, array|null}> */
    public array $putCalls = [];

    public function __construct() {}

    /** Queue a response for a specific GET call (exact param match). */
    public function onGet(string $method, ?array $params, stdClass | Throwable $response): static
    {
        $this->responses['GET:' . $method . ':' . json_encode($params)] = $response;

        return $this;
    }

    /** Queue a fallback response for any call to a given method, regardless of params. */
    public function onAnyGet(string $method, stdClass | Throwable $response): static
    {
        $this->responses['GET:' . $method . ':*'] = $response;

        return $this;
    }

    public function get(string $method, ?array $params = []): stdClass
    {
        $this->getCalls[] = [$method, $params];

        $specific = 'GET:' . $method . ':' . json_encode($params);
        $wildcard = 'GET:' . $method . ':*';

        $resp = $this->responses[$specific] ?? $this->responses[$wildcard] ?? null;

        if ($resp === null) {
            throw new RuntimeException("Unexpected GET call: {$method} " . json_encode($params));
        }

        if ($resp instanceof Throwable) {
            throw $resp;
        }

        return $resp;
    }

    public function put(string $method, string $content, ?array $params = []): stdClass
    {
        $this->putCalls[] = [$method, $content, $params];

        return new stdClass;
    }
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

function fakeRequest(): FakeRequest
{
    return new FakeRequest;
}

function pcloudAdapter(FakeRequest $req, string $root = '/'): PcloudAdapter
{
    return new PcloudAdapter($req, $root);
}

function statResp(array $attrs): stdClass
{
    $metadata = new stdClass;
    foreach ($attrs as $k => $v) {
        $metadata->$k = $v;
    }
    $resp = new stdClass;
    $resp->metadata = $metadata;

    return $resp;
}

function listFolderResp(array $contents = [], int $folderid = 0): stdClass
{
    $metadata = new stdClass;
    $metadata->folderid = $folderid;
    $metadata->name = 'root';
    $metadata->isfolder = true;
    $metadata->contents = $contents;

    $resp = new stdClass;
    $resp->metadata = $metadata;

    return $resp;
}

function fileItem(string $name, int $fileid = 1, int $size = 100, string $contenttype = 'text/plain', string $modified = 'Thu, 01 Jan 2026 00:00:00 +0000'): stdClass
{
    $item = new stdClass;
    $item->name = $name;
    $item->fileid = $fileid;
    $item->size = $size;
    $item->contenttype = $contenttype;
    $item->modified = $modified;
    $item->isfolder = false;

    return $item;
}

function folderItem(string $name, int $folderid = 10): stdClass
{
    $item = new stdClass;
    $item->name = $name;
    $item->folderid = $folderid;
    $item->isfolder = true;
    $item->modified = 'Thu, 01 Jan 2026 00:00:00 +0000';

    return $item;
}

function uploadResp(int $uploadid): stdClass
{
    $r = new stdClass;
    $r->uploadid = $uploadid;

    return $r;
}

function folderCreateResp(int $folderid): stdClass
{
    $meta = new stdClass;
    $meta->folderid = $folderid;
    $r = new stdClass;
    $r->metadata = $meta;

    return $r;
}

// ─── fileExists ──────────────────────────────────────────────────────────────

describe('fileExists', function () {
    it('returns true when stat returns a file', function () {
        $req = fakeRequest()->onGet('stat', ['path' => '/file.txt'], statResp(['isfolder' => false]));

        expect(pcloudAdapter($req)->fileExists('file.txt'))->toBeTrue();
    });

    it('returns false when stat returns a folder', function () {
        $req = fakeRequest()->onGet('stat', ['path' => '/dir'], statResp(['isfolder' => true]));

        expect(pcloudAdapter($req)->fileExists('dir'))->toBeFalse();
    });

    it('returns false when stat throws', function () {
        $req = fakeRequest()->onGet('stat', ['path' => '/missing.txt'], new PcloudException('not found'));

        expect(pcloudAdapter($req)->fileExists('missing.txt'))->toBeFalse();
    });

    it('prefixes the path with a custom root', function () {
        $req = fakeRequest()->onGet('stat', ['path' => '/MyApp/file.txt'], statResp(['isfolder' => false]));

        expect(pcloudAdapter($req, '/MyApp')->fileExists('file.txt'))->toBeTrue();
    });
});

// ─── directoryExists ─────────────────────────────────────────────────────────

describe('directoryExists', function () {
    it('returns true when stat returns a folder', function () {
        $req = fakeRequest()->onGet('stat', ['path' => '/images'], statResp(['isfolder' => true]));

        expect(pcloudAdapter($req)->directoryExists('images'))->toBeTrue();
    });

    it('returns false when stat returns a file', function () {
        $req = fakeRequest()->onGet('stat', ['path' => '/file.txt'], statResp(['isfolder' => false]));

        expect(pcloudAdapter($req)->directoryExists('file.txt'))->toBeFalse();
    });

    it('returns false when stat throws', function () {
        $req = fakeRequest()->onGet('stat', ['path' => '/missing'], new PcloudException('not found'));

        expect(pcloudAdapter($req)->directoryExists('missing'))->toBeFalse();
    });
});

// ─── write ───────────────────────────────────────────────────────────────────

describe('write', function () {
    it('deletes any existing file, then uploads content', function () {
        $req = fakeRequest()
            ->onAnyGet('deletefile', new PcloudException('not found'))
            ->onAnyGet('upload_create', uploadResp(7))
            ->onGet('upload_save', ['uploadid' => 7, 'name' => 'hello.txt', 'folderid' => 0], new stdClass);

        pcloudAdapter($req)->write('hello.txt', 'hello', new Config);

        $putMethods = array_column($req->putCalls, 0);
        expect($putMethods)->toContain('upload_write');

        $getMethods = array_column($req->getCalls, 0);
        expect($getMethods)->toContain('deletefile');
        expect($getMethods)->toContain('upload_create');
        expect($getMethods)->toContain('upload_save');
    });

    it('uploads empty content', function () {
        $req = fakeRequest()
            ->onAnyGet('deletefile', new PcloudException('not found'))
            ->onAnyGet('upload_create', uploadResp(9))
            ->onGet('upload_save', ['uploadid' => 9, 'name' => 'empty.txt', 'folderid' => 0], new stdClass);

        pcloudAdapter($req)->write('empty.txt', '', new Config);

        $putCalls = $req->putCalls;
        expect($putCalls)->toHaveCount(1);
        expect($putCalls[0][1])->toBe(''); // content is empty string
    });

    it('creates the parent directory when it does not exist', function () {
        $req = fakeRequest()
            ->onGet('listfolder', ['path' => '/dir', 'norecursive' => 1], new PcloudException('not found'))
            ->onGet('createfolder', ['name' => 'dir', 'folderid' => 0], folderCreateResp(42))
            ->onAnyGet('deletefile', new PcloudException('not found'))
            ->onAnyGet('upload_create', uploadResp(1))
            ->onGet('upload_save', ['uploadid' => 1, 'name' => 'file.txt', 'folderid' => 42], new stdClass);

        pcloudAdapter($req)->write('dir/file.txt', 'hi', new Config);

        $getMethods = array_column($req->getCalls, 0);
        expect($getMethods)->toContain('createfolder');
        expect($getMethods)->toContain('upload_save');
    });

    it('throws UnableToWriteFile on API failure', function () {
        $req = fakeRequest()
            ->onAnyGet('deletefile', new PcloudException('not found'))
            ->onGet('upload_create', null, new PcloudException('API error'));

        expect(fn () => pcloudAdapter($req)->write('fail.txt', 'x', new Config))->toThrow(UnableToWriteFile::class);
    });
});

// ─── writeStream ─────────────────────────────────────────────────────────────

describe('writeStream', function () {
    it('uploads content from a stream', function () {
        $req = fakeRequest()
            ->onAnyGet('deletefile', new PcloudException('not found'))
            ->onAnyGet('upload_create', uploadResp(3))
            ->onGet('upload_save', ['uploadid' => 3, 'name' => 'stream.txt', 'folderid' => 0], new stdClass);

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, 'stream content');
        rewind($stream);

        pcloudAdapter($req)->writeStream('stream.txt', $stream, new Config);
        fclose($stream);

        $putCalls = array_filter($req->putCalls, fn ($c) => $c[0] === 'upload_write');
        expect($putCalls)->not->toBeEmpty();

        $firstPut = array_values($putCalls)[0];
        expect($firstPut[1])->toBe('stream content');
    });

    it('throws UnableToWriteFile on API failure', function () {
        $req = fakeRequest()
            ->onAnyGet('deletefile', new PcloudException('not found'))
            ->onGet('upload_create', null, new PcloudException('API error'));

        $stream = fopen('php://temp', 'r+');

        expect(fn () => pcloudAdapter($req)->writeStream('fail.txt', $stream, new Config))->toThrow(UnableToWriteFile::class);
        fclose($stream);
    });
});

// ─── readStream ───────────────────────────────────────────────────────────────

describe('readStream', function () {
    it('calls getfilelink with the full path', function () {
        $fileLinkResp = new stdClass;
        $fileLinkResp->hosts = ['cdn.pcloud.com'];
        $fileLinkResp->path = '/dl/abc/photo.jpg';

        $req = fakeRequest()->onGet('getfilelink', ['path' => '/photo.jpg'], $fileLinkResp);

        try {
            pcloudAdapter($req)->readStream('photo.jpg');
        } catch (UnableToReadFile) {
            // fopen of URL is expected to fail in unit tests
        }

        $getCalls = array_column($req->getCalls, 0);
        expect($getCalls)->toContain('getfilelink');
    });

    it('throws UnableToReadFile when getfilelink fails', function () {
        $req = fakeRequest()->onGet('getfilelink', ['path' => '/missing.jpg'], new PcloudException('not found'));

        expect(fn () => pcloudAdapter($req)->readStream('missing.jpg'))->toThrow(UnableToReadFile::class);
    });
});

// ─── delete ──────────────────────────────────────────────────────────────────

describe('delete', function () {
    it('calls deletefile with the correct path', function () {
        $req = fakeRequest()->onGet('deletefile', ['path' => '/old.txt'], new stdClass);

        pcloudAdapter($req)->delete('old.txt');

        expect(array_column($req->getCalls, 0))->toContain('deletefile');
    });

    it('throws UnableToDeleteFile on API failure', function () {
        $req = fakeRequest()->onGet('deletefile', ['path' => '/old.txt'], new PcloudException('API error'));

        expect(fn () => pcloudAdapter($req)->delete('old.txt'))->toThrow(UnableToDeleteFile::class);
    });
});

// ─── deleteDirectory ─────────────────────────────────────────────────────────

describe('deleteDirectory', function () {
    it('calls deletefolderrecursive with the correct path', function () {
        $req = fakeRequest()->onGet('deletefolderrecursive', ['path' => '/old-dir'], new stdClass);

        pcloudAdapter($req)->deleteDirectory('old-dir');

        expect(array_column($req->getCalls, 0))->toContain('deletefolderrecursive');
    });

    it('throws UnableToDeleteDirectory on API failure', function () {
        $req = fakeRequest()->onGet('deletefolderrecursive', ['path' => '/old-dir'], new PcloudException('API error'));

        expect(fn () => pcloudAdapter($req)->deleteDirectory('old-dir'))->toThrow(UnableToDeleteDirectory::class);
    });
});

// ─── createDirectory ─────────────────────────────────────────────────────────

describe('createDirectory', function () {
    it('skips creation when the folder already exists', function () {
        $req = fakeRequest()->onGet('listfolder', ['path' => '/existing', 'norecursive' => 1], listFolderResp([], 55));

        pcloudAdapter($req)->createDirectory('existing', new Config);

        $getMethods = array_column($req->getCalls, 0);
        expect($getMethods)->not->toContain('createfolder');
    });

    it('creates a missing folder', function () {
        $req = fakeRequest()
            ->onGet('listfolder', ['path' => '/new-dir', 'norecursive' => 1], new PcloudException('not found'))
            ->onGet('createfolder', ['name' => 'new-dir', 'folderid' => 0], folderCreateResp(99));

        pcloudAdapter($req)->createDirectory('new-dir', new Config);

        $getMethods = array_column($req->getCalls, 0);
        expect($getMethods)->toContain('createfolder');
    });

    it('creates nested directories recursively', function () {
        $req = fakeRequest()
            ->onGet('listfolder', ['path' => '/a', 'norecursive' => 1], new PcloudException('not found'))
            ->onGet('createfolder', ['name' => 'a', 'folderid' => 0], folderCreateResp(10))
            ->onGet('listfolder', ['path' => '/a/b', 'norecursive' => 1], new PcloudException('not found'))
            ->onGet('createfolder', ['name' => 'b', 'folderid' => 10], folderCreateResp(11));

        pcloudAdapter($req)->createDirectory('a/b', new Config);

        $getMethods = array_column($req->getCalls, 0);
        expect(array_filter($getMethods, fn ($m) => $m === 'createfolder'))->toHaveCount(2);
    });
});

// ─── listContents ─────────────────────────────────────────────────────────────

describe('listContents', function () {
    it('yields FileAttributes and DirectoryAttributes (shallow)', function () {
        $req = fakeRequest()->onGet(
            'listfolder',
            ['path' => '/', 'norecursive' => 1],
            listFolderResp([
                fileItem('photo.jpg', fileid: 1, size: 2048, contenttype: 'image/jpeg'),
                folderItem('docs', folderid: 20),
            ])
        );

        $results = iterator_to_array(pcloudAdapter($req)->listContents('', false));

        expect($results)->toHaveCount(2);
        expect($results[0])->toBeInstanceOf(FileAttributes::class);
        expect($results[0]->path())->toBe('photo.jpg');
        expect($results[0]->fileSize())->toBe(2048);
        expect($results[0]->mimeType())->toBe('image/jpeg');
        expect($results[1])->toBeInstanceOf(DirectoryAttributes::class);
        expect($results[1]->path())->toBe('docs');
    });

    it('recurses into subdirectories when deep=true', function () {
        $req = fakeRequest()
            ->onGet('listfolder', ['path' => '/', 'norecursive' => 1], listFolderResp([folderItem('sub', folderid: 5)]))
            ->onGet('listfolder', ['path' => '/sub', 'norecursive' => 1], listFolderResp([fileItem('file.txt', fileid: 9)]));

        $results = iterator_to_array(pcloudAdapter($req)->listContents('', true), false);

        expect($results)->toHaveCount(2);
        expect($results[0])->toBeInstanceOf(DirectoryAttributes::class);
        expect($results[0]->path())->toBe('sub');
        expect($results[1])->toBeInstanceOf(FileAttributes::class);
        expect($results[1]->path())->toBe('sub/file.txt');
    });

    it('returns an empty iterable for a missing directory', function () {
        $req = fakeRequest()->onAnyGet('listfolder', new PcloudException('not found'));

        $results = iterator_to_array(pcloudAdapter($req)->listContents('missing', false));

        expect($results)->toBeEmpty();
    });

    it('strips the root prefix from returned paths', function () {
        $req = fakeRequest()->onGet(
            'listfolder',
            ['path' => '/MyApp', 'norecursive' => 1],
            listFolderResp([fileItem('doc.pdf', fileid: 3)], 7)
        );

        $results = iterator_to_array(pcloudAdapter($req, '/MyApp')->listContents('', false));

        expect($results[0]->path())->toBe('doc.pdf');
    });
});

// ─── move ────────────────────────────────────────────────────────────────────

describe('move', function () {
    it('calls renamefile when the source is a file', function () {
        $req = fakeRequest()
            ->onGet('stat', ['path' => '/src.txt'], statResp(['isfolder' => false]))
            ->onAnyGet('deletefile', new PcloudException('not found'))
            ->onGet('renamefile', ['path' => '/src.txt', 'topath' => '/dst.txt'], new stdClass);

        pcloudAdapter($req)->move('src.txt', 'dst.txt', new Config);

        expect(array_column($req->getCalls, 0))->toContain('renamefile');
    });

    it('calls renamefolder when the source is a directory', function () {
        $req = fakeRequest()
            ->onGet('stat', ['path' => '/old-dir'], statResp(['isfolder' => true]))
            ->onGet('renamefolder', ['path' => '/old-dir', 'topath' => '/new-dir'], new stdClass);

        pcloudAdapter($req)->move('old-dir', 'new-dir', new Config);

        expect(array_column($req->getCalls, 0))->toContain('renamefolder');
    });
});

// ─── copy ────────────────────────────────────────────────────────────────────

describe('copy', function () {
    it('calls copyfile with source and destination paths', function () {
        $req = fakeRequest()
            ->onAnyGet('deletefile', new PcloudException('not found'))
            ->onGet('copyfile', ['path' => '/src.txt', 'topath' => '/dst.txt'], new stdClass);

        pcloudAdapter($req)->copy('src.txt', 'dst.txt', new Config);

        expect(array_column($req->getCalls, 0))->toContain('copyfile');
    });
});

// ─── metadata ────────────────────────────────────────────────────────────────

describe('mimeType', function () {
    it('returns the content type from stat', function () {
        $req = fakeRequest()->onGet('stat', ['path' => '/image.png'], statResp(['contenttype' => 'image/png']));

        $attrs = pcloudAdapter($req)->mimeType('image.png');

        expect($attrs)->toBeInstanceOf(FileAttributes::class);
        expect($attrs->mimeType())->toBe('image/png');
    });
});

describe('lastModified', function () {
    it('returns the unix timestamp parsed from the modified field', function () {
        $date = 'Thu, 01 Jan 2026 00:00:00 +0000';
        $req = fakeRequest()->onGet('stat', ['path' => '/file.txt'], statResp(['modified' => $date]));

        $attrs = pcloudAdapter($req)->lastModified('file.txt');

        expect($attrs)->toBeInstanceOf(FileAttributes::class);
        expect($attrs->lastModified())->toBe(strtotime($date));
    });
});

describe('fileSize', function () {
    it('returns the file size in bytes from stat', function () {
        $req = fakeRequest()->onGet('stat', ['path' => '/big.zip'], statResp(['size' => 1048576]));

        $attrs = pcloudAdapter($req)->fileSize('big.zip');

        expect($attrs)->toBeInstanceOf(FileAttributes::class);
        expect($attrs->fileSize())->toBe(1048576);
    });
});

// ─── visibility ───────────────────────────────────────────────────────────────

describe('visibility', function () {
    it('always returns public visibility', function () {
        $req = fakeRequest()->onGet('stat', ['path' => '/file.txt'], statResp(['isfolder' => false]));

        $attrs = pcloudAdapter($req)->visibility('file.txt');

        expect($attrs->visibility())->toBe(Visibility::PUBLIC);
    });

    it('throws UnableToSetVisibility', function () {
        $req = fakeRequest();

        expect(fn () => pcloudAdapter($req)->setVisibility('file.txt', Visibility::PRIVATE))
            ->toThrow(UnableToSetVisibility::class);
    });
});

describe('getUrl', function () {
    it('returns the public link from getfilepublink', function () {
        $resp = new stdClass;
        $resp->link = 'https://u.pcloud.link/publink/show?code=abc123';

        $req = fakeRequest()->onGet('getfilepublink', ['path' => '/root/image.jpg'], $resp);

        $url = pcloudAdapter($req, '/root')->getUrl('image.jpg');

        expect($url)->toBe('https://u.pcloud.link/publink/show?code=abc123');
    });

    it('throws RuntimeException when getfilepublink fails', function () {
        $req = fakeRequest()->onGet('getfilepublink', ['path' => '/root/image.jpg'], new PcloudException('Access denied'));

        expect(fn () => pcloudAdapter($req, '/root')->getUrl('image.jpg'))
            ->toThrow(RuntimeException::class, 'Unable to get public URL for: image.jpg');
    });
});

describe('getTemporaryUrl', function () {
    it('passes the expiration timestamp to getfilepublink and returns the link', function () {
        $expiration = new DateTimeImmutable('2026-12-31 23:59:59', new DateTimeZone('UTC'));

        $resp = new stdClass;
        $resp->link = 'https://u.pcloud.link/publink/show?code=tmp456';

        $req = fakeRequest()->onGet('getfilepublink', [
            'path' => '/root/image.jpg',
            'expire' => $expiration->getTimestamp(),
        ], $resp);

        $url = pcloudAdapter($req, '/root')->getTemporaryUrl('image.jpg', $expiration);

        expect($url)->toBe('https://u.pcloud.link/publink/show?code=tmp456');
    });

    it('throws RuntimeException when getfilepublink fails', function () {
        $expiration = new DateTimeImmutable('+1 hour');

        $req = fakeRequest()->onAnyGet('getfilepublink', new PcloudException('Access denied'));

        expect(fn () => pcloudAdapter($req, '/root')->getTemporaryUrl('image.jpg', $expiration))
            ->toThrow(RuntimeException::class, 'Unable to get temporary URL for: image.jpg');
    });
});
