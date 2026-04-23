<?php

declare(strict_types=1);

namespace Leobsst\LaravelPcloudFilesystem;

use Generator;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\Visibility;
use pCloud\Sdk\Exception as PcloudException;
use pCloud\Sdk\Request;
use Throwable;

class PcloudAdapter implements FilesystemAdapter
{
    private Request $request;

    private string $root;

    /** @var array<string, int> */
    private array $folderIdCache = [];

    public function __construct(Request $request, string $root = '/')
    {
        $this->request = $request;
        $this->root = '/' . trim($root, '/');
    }

    private function fullPath(string $path): string
    {
        $path = ltrim($path, '/');

        return $path === '' ? $this->root : rtrim($this->root, '/') . '/' . $path;
    }

    private function relativePath(string $fullPath): string
    {
        $prefix = rtrim($this->root, '/') . '/';

        if (str_starts_with($fullPath, $prefix)) {
            return substr($fullPath, strlen($prefix));
        }

        return ltrim($fullPath, '/');
    }

    /**
     * Get metadata for any item via pCloud's stat API.
     *
     * @throws PcloudException
     */
    private function stat(string $fullPath): \stdClass
    {
        $response = $this->request->get('stat', ['path' => $fullPath]);

        return $response->metadata;
    }

    /**
     * Resolve a folder path to its pCloud folder ID.
     * Uses listfolder (guaranteed to support path param) and caches results.
     *
     * @throws PcloudException
     */
    private function getFolderIdForPath(string $fullPath): int
    {
        if ($fullPath === '/' || $fullPath === '') {
            return 0;
        }

        if (isset($this->folderIdCache[$fullPath])) {
            return $this->folderIdCache[$fullPath];
        }

        $response = $this->request->get('listfolder', [
            'path' => $fullPath,
            'norecursive' => 1,
        ]);

        $id = (int) $response->metadata->folderid;
        $this->folderIdCache[$fullPath] = $id;

        return $id;
    }

    /**
     * Create all folders in path that don't already exist.
     *
     * @throws PcloudException
     */
    private function ensureDirectory(string $fullPath): void
    {
        $parts = array_values(array_filter(explode('/', $fullPath)));
        $currentPath = '';

        foreach ($parts as $part) {
            $currentPath .= '/' . $part;

            if (isset($this->folderIdCache[$currentPath])) {
                continue;
            }

            try {
                $id = $this->getFolderIdForPath($currentPath);
                $this->folderIdCache[$currentPath] = $id;
            } catch (PcloudException) {
                $parentPath = dirname($currentPath);
                $parentId = ($parentPath === '/' || $parentPath === '.') ? 0 : $this->getFolderIdForPath($parentPath);

                $response = $this->request->get('createfolder', [
                    'name' => $part,
                    'folderid' => $parentId,
                ]);

                if (property_exists($response, 'metadata')) {
                    $this->folderIdCache[$currentPath] = (int) $response->metadata->folderid;
                }
            }
        }
    }

    /**
     * Upload a string as a file using pCloud's chunked upload protocol.
     *
     * @throws PcloudException
     */
    private function uploadContent(string $contents, string $filename, int $folderId): void
    {
        $upload = $this->request->get('upload_create');
        $uploadId = $upload->uploadid;
        $partSize = 10485760; // 10 MB
        $total = strlen($contents);
        $offset = 0;

        if ($total === 0) {
            $this->request->put('upload_write', '', ['uploadid' => $uploadId, 'uploadoffset' => 0]);
        } else {
            while ($offset < $total) {
                $chunk = substr($contents, $offset, $partSize);
                $this->request->put('upload_write', $chunk, ['uploadid' => $uploadId, 'uploadoffset' => $offset]);
                $offset += strlen($chunk);
            }
        }

        $this->request->get('upload_save', [
            'uploadid' => $uploadId,
            'name' => $filename,
            'folderid' => $folderId,
        ]);
    }

    /**
     * Upload a stream as a file using pCloud's chunked upload protocol.
     *
     * @param  resource  $stream
     *
     * @throws PcloudException
     */
    private function uploadStream($stream, string $filename, int $folderId): void
    {
        $upload = $this->request->get('upload_create');
        $uploadId = $upload->uploadid;
        $partSize = 10485760; // 10 MB
        $offset = 0;
        $uploaded = false;

        while (! feof($stream)) {
            $chunk = fread($stream, $partSize);
            if ($chunk === false) {
                break;
            }
            $this->request->put('upload_write', $chunk, ['uploadid' => $uploadId, 'uploadoffset' => $offset]);
            $offset += strlen($chunk);
            $uploaded = true;
        }

        if (! $uploaded) {
            $this->request->put('upload_write', '', ['uploadid' => $uploadId, 'uploadoffset' => 0]);
        }

        $this->request->get('upload_save', [
            'uploadid' => $uploadId,
            'name' => $filename,
            'folderid' => $folderId,
        ]);
    }

    public function fileExists(string $path): bool
    {
        try {
            $metadata = $this->stat($this->fullPath($path));

            return ! ($metadata->isfolder ?? false);
        } catch (Throwable) {
            return false;
        }
    }

    public function directoryExists(string $path): bool
    {
        try {
            $metadata = $this->stat($this->fullPath($path));

            return (bool) ($metadata->isfolder ?? false);
        } catch (Throwable) {
            return false;
        }
    }

    public function write(string $path, string $contents, Config $config): void
    {
        try {
            $fullPath = $this->fullPath($path);
            $parentPath = dirname($fullPath);
            $filename = basename($fullPath);

            $this->ensureDirectory($parentPath);
            $parentId = $this->getFolderIdForPath($parentPath);

            // Overwrite existing file if present
            try {
                $this->request->get('deletefile', ['path' => $fullPath]);
            } catch (Throwable) {
            }

            $this->uploadContent($contents, $filename, $parentId);
        } catch (Throwable $e) {
            throw UnableToWriteFile::atLocation($path, $e->getMessage(), $e);
        }
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        try {
            $fullPath = $this->fullPath($path);
            $parentPath = dirname($fullPath);
            $filename = basename($fullPath);

            $this->ensureDirectory($parentPath);
            $parentId = $this->getFolderIdForPath($parentPath);

            try {
                $this->request->get('deletefile', ['path' => $fullPath]);
            } catch (Throwable) {
            }

            $this->uploadStream($contents, $filename, $parentId);
        } catch (Throwable $e) {
            throw UnableToWriteFile::atLocation($path, $e->getMessage(), $e);
        }
    }

    public function read(string $path): string
    {
        $stream = $this->readStream($path);

        try {
            $contents = stream_get_contents($stream);
            if ($contents === false) {
                throw new \RuntimeException('stream_get_contents failed');
            }

            return $contents;
        } catch (UnableToReadFile $e) {
            throw $e;
        } catch (Throwable $e) {
            throw UnableToReadFile::fromLocation($path, $e->getMessage(), $e);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function readStream(string $path)
    {
        try {
            $fullPath = $this->fullPath($path);
            $response = $this->request->get('getfilelink', ['path' => $fullPath]);
            $url = 'https://' . $response->hosts[0] . $response->path;

            $context = stream_context_create([
                'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
            ]);

            $stream = fopen($url, 'rb', false, $context);

            if (! is_resource($stream)) {
                throw new \RuntimeException('Failed to open stream from: ' . $url);
            }

            return $stream;
        } catch (Throwable $e) {
            throw UnableToReadFile::fromLocation($path, $e->getMessage(), $e);
        }
    }

    public function delete(string $path): void
    {
        try {
            $this->request->get('deletefile', ['path' => $this->fullPath($path)]);
        } catch (Throwable $e) {
            throw UnableToDeleteFile::atLocation($path, $e->getMessage(), $e);
        }
    }

    public function deleteDirectory(string $path): void
    {
        try {
            $this->request->get('deletefolderrecursive', ['path' => $this->fullPath($path)]);
        } catch (Throwable $e) {
            throw UnableToDeleteDirectory::atLocation($path, $e->getMessage(), $e);
        }
    }

    public function createDirectory(string $path, Config $config): void
    {
        try {
            $this->ensureDirectory($this->fullPath($path));
        } catch (Throwable $e) {
            throw UnableToCreateDirectory::atLocation($path, $e->getMessage(), $e);
        }
    }

    public function setVisibility(string $path, string $visibility): void
    {
        throw UnableToSetVisibility::atLocation($path, 'pCloud does not support visibility control.');
    }

    public function visibility(string $path): FileAttributes
    {
        try {
            $this->stat($this->fullPath($path));
        } catch (Throwable $e) {
            throw UnableToRetrieveMetadata::visibility($path, $e->getMessage(), $e);
        }

        return new FileAttributes($path, null, Visibility::PUBLIC);
    }

    public function mimeType(string $path): FileAttributes
    {
        try {
            $metadata = $this->stat($this->fullPath($path));

            return new FileAttributes($path, null, null, null, $metadata->contenttype ?? null);
        } catch (Throwable $e) {
            throw UnableToRetrieveMetadata::mimeType($path, $e->getMessage(), $e);
        }
    }

    public function lastModified(string $path): FileAttributes
    {
        try {
            $metadata = $this->stat($this->fullPath($path));
            $timestamp = isset($metadata->modified) ? strtotime($metadata->modified) : null;

            return new FileAttributes($path, null, null, $timestamp ?: null);
        } catch (Throwable $e) {
            throw UnableToRetrieveMetadata::lastModified($path, $e->getMessage(), $e);
        }
    }

    public function fileSize(string $path): FileAttributes
    {
        try {
            $metadata = $this->stat($this->fullPath($path));

            return new FileAttributes($path, $metadata->size ?? null);
        } catch (Throwable $e) {
            throw UnableToRetrieveMetadata::fileSize($path, $e->getMessage(), $e);
        }
    }

    public function listContents(string $path, bool $deep): iterable
    {
        yield from $this->doListContents($this->fullPath($path), $deep);
    }

    private function doListContents(string $fullPath, bool $deep): Generator
    {
        try {
            $response = $this->request->get('listfolder', [
                'path' => $fullPath,
                'norecursive' => 1,
            ]);
        } catch (Throwable) {
            return;
        }

        foreach ($response->metadata->contents ?? [] as $item) {
            $itemFullPath = rtrim($fullPath, '/') . '/' . $item->name;
            $relativePath = $this->relativePath($itemFullPath);

            if ($item->isfolder ?? false) {
                $this->folderIdCache[$itemFullPath] = (int) ($item->folderid ?? 0);
                yield new DirectoryAttributes($relativePath);

                if ($deep) {
                    yield from $this->doListContents($itemFullPath, true);
                }
            } else {
                yield new FileAttributes(
                    $relativePath,
                    $item->size ?? null,
                    null,
                    isset($item->modified) ? (strtotime($item->modified) ?: null) : null,
                    $item->contenttype ?? null,
                );
            }
        }
    }

    public function move(string $source, string $destination, Config $config): void
    {
        try {
            $fullSource = $this->fullPath($source);
            $fullDest = $this->fullPath($destination);

            $this->ensureDirectory(dirname($fullDest));

            $metadata = $this->stat($fullSource);

            if ($metadata->isfolder ?? false) {
                $this->request->get('renamefolder', [
                    'path' => $fullSource,
                    'topath' => $fullDest,
                ]);
            } else {
                try {
                    $this->request->get('deletefile', ['path' => $fullDest]);
                } catch (Throwable) {
                }

                $this->request->get('renamefile', [
                    'path' => $fullSource,
                    'topath' => $fullDest,
                ]);
            }
        } catch (Throwable $e) {
            throw UnableToMoveFile::fromLocationTo($source, $destination, $e);
        }
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        try {
            $fullSource = $this->fullPath($source);
            $fullDest = $this->fullPath($destination);

            $this->ensureDirectory(dirname($fullDest));

            try {
                $this->request->get('deletefile', ['path' => $fullDest]);
            } catch (Throwable) {
            }

            $this->request->get('copyfile', [
                'path' => $fullSource,
                'topath' => $fullDest,
            ]);
        } catch (Throwable $e) {
            throw UnableToCopyFile::fromLocationTo($source, $destination, $e);
        }
    }
}
