<?php

declare(strict_types=1);

namespace Leobsst\LaravelPcloudFilesystem\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToRetrieveMetadata;
use Leobsst\LaravelPcloudFilesystem\PcloudAdapter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a file of a pCloud disk through the application (the proxy URL returned
 * by url() / temporaryUrl()).
 *
 * - A signed URL (temporaryUrl) is always checked; a disk with 'visibility' => 'private'
 *   only serves signed URLs.
 * - Metadata is fetched with a single cached `stat`; browsers get ETag / Last-Modified /
 *   Cache-Control so repeated views end in a 304 without downloading from pCloud.
 * - Only types that cannot run scripts are rendered inline (no SVG / HTML).
 */
class ProxyController extends Controller
{
    /**
     * @var list<string>
     */
    private const INLINE_MIME_TYPES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/bmp', 'image/x-icon',
        'application/pdf',
        'video/mp4', 'video/webm', 'video/ogg', 'audio/mpeg', 'audio/ogg', 'audio/wav', 'audio/webm',
        'text/plain', 'text/csv',
    ];

    public function __invoke(Request $request, string $path): Response | StreamedResponse
    {
        // Route default (not a URI segment): read it by name, route parameters are passed by position.
        $disk = (string) $request->route('disk');
        $config = (array) config("filesystems.disks.{$disk}", []);
        $signed = $request->hasAny(['expires', 'signature']);

        if (($signed || ($config['visibility'] ?? null) === 'private') && ! $request->hasValidSignature()) {
            abort(403);
        }

        abort_unless(PcloudAdapter::isSafePath($path), 404);

        $adapter = Storage::disk($disk)->getAdapter();
        abort_unless($adapter instanceof PcloudAdapter, 404);

        $metadata = $this->metadata($disk, $path, $adapter, $config);
        abort_if($metadata === null, 404);

        $mime = $metadata['mime_type'] ?: 'application/octet-stream';
        $inline = \in_array($mime, self::INLINE_MIME_TYPES, true);
        $headers = array_filter([
            'Content-Type' => $inline ? $mime : 'application/octet-stream',
            'Content-Disposition' => ($inline ? 'inline' : 'attachment') . '; filename="' . addcslashes(basename($path), '"\\') . '"',
            'X-Content-Type-Options' => 'nosniff',
            'ETag' => $this->etag($metadata),
            'Last-Modified' => $metadata['last_modified'] !== null ? gmdate('D, d M Y H:i:s', $metadata['last_modified']) . ' GMT' : null,
            'Cache-Control' => $signed
                ? 'private, max-age=' . max(0, min((int) $request->query('expires') - time(), $this->maxAge($config)))
                : 'public, max-age=' . $this->maxAge($config),
        ], fn (?string $value): bool => $value !== null);

        $notModified = new Response('', 304, $headers);

        if ($notModified->isNotModified($request)) {
            return $notModified;
        }

        if ($metadata['size'] !== null) {
            $headers['Content-Length'] = (string) $metadata['size'];
        }

        return new StreamedResponse(function () use ($adapter, $path): void {
            $stream = $adapter->readStream($path);
            fpassthru($stream);
            fclose($stream);
        }, 200, $headers);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{size: int|null, mime_type: string|null, last_modified: int|null, hash: string|null}|null
     */
    private function metadata(string $disk, string $path, PcloudAdapter $adapter, array $config): ?array
    {
        $ttl = (int) ($config['proxy_metadata_ttl'] ?? 300);
        $resolve = function () use ($adapter, $path): ?array {
            try {
                return $adapter->metadata($path);
            } catch (UnableToRetrieveMetadata) {
                return null;
            }
        };

        if ($ttl <= 0) {
            return $resolve();
        }

        $key = 'pcloud-filesystem:metadata:' . md5($disk . '|' . $path);
        $metadata = Cache::get($key);

        if (! \is_array($metadata)) {
            $metadata = $resolve();

            // Missing files are not cached: an upload must become visible immediately.
            if ($metadata !== null) {
                Cache::put($key, $metadata, $ttl);
            }
        }

        return $metadata;
    }

    /**
     * @param  array{size: int|null, mime_type: string|null, last_modified: int|null, hash: string|null}  $metadata
     */
    private function etag(array $metadata): string
    {
        return '"' . ($metadata['hash'] ?? md5($metadata['size'] . '|' . $metadata['last_modified'])) . '"';
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function maxAge(array $config): int
    {
        return max(0, (int) ($config['proxy_max_age'] ?? 86400));
    }
}
