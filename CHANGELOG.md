# Changelog

All notable changes to `laravel-pcloud-filesystem` will be documented in this file.

## v1.0.7 - 2026-04-26

### Bug Fixes

#### `url()` returns pCloud CDN URL instead of proxy URL

`Storage::disk('uploads')->url($path)` retournait systématiquement une URL pCloud CDN directe
(`https://eXXX.pcloud.com/…`) au lieu de l'URL proxy (`/assets/uploads/…`).

**Cause** : Laravel's `FilesystemAdapter::url()` detects `getUrl()` on the adapter via
`method_exists` and calls it with absolute priority, ignoring the `url` config key entirely.
Additionally, the service provider had an inverted condition that prevented proxy URL injection
for pCloud disks.

**Fix** :

- Corrected the inverted condition in `bootingPackage()` (`!isPcloudDisk()` instead of `isPcloudDisk()`)
- The proxy URL is now passed to `PcloudAdapter` via the constructor
- `getUrl()` returns the proxy URL directly when one is configured, falling back to the pCloud API otherwise

#### `temporaryUrl()` generates IP-restricted pCloud CDN links

`Storage::disk('uploads')->temporaryUrl($path, $expiration)` returned a pCloud-signed CDN URL
that is restricted to the requesting IP address, causing broken links for users behind proxies,
load balancers, or mobile networks.

**Fix** :

- Proxy routes are now named (`pcloud-filesystem.{disk-slug}`)
- The route name is injected into disk config (`proxy_route`) and passed to `PcloudAdapter`
- `getTemporaryUrl()` generates a Laravel signed URL via `URL::temporarySignedRoute()` when a
  proxy route is configured, routing through the application instead of pCloud CDN
- The proxy route validates the signature only when an `expires` parameter is present — unsigned
  access via `url()` continues to work normally; tampered or expired signed URLs return 403

**Full Changelog**: https://github.com/leobsst/laravel-pcloud-filesystem/compare/v1.0.6...v1.0.7

## v1.0.6 - 2026-04-26

### Fixed

* Wrong service provider condition

**Full Changelog**: https://github.com/leobsst/laravel-pcloud-filesystem/compare/v1.0.5...v1.0.6

## v1.0.5 - 2026-04-25

### What's changed

#### Improve middleware and provider

- use LocationEnum instead of magic number
- use DiskConfig dto to interact wtih file system disks configuration

**Full Changelog**: https://github.com/leobsst/laravel-pcloud-filesystem/compare/v1.0.4...v1.0.5

## v1.0.4 - 2026-04-25

### Added

- **Streaming proxy** — The package now registers a route `/assets/{disk-name}/{path}` for every `pcloud` disk. Requests go through your Laravel server, which fetches the file from pCloud using a consistent server-side IP and streams the content to the browser. This completely eliminates the pCloud IP-restriction error when displaying images or videos from different client IPs.
- **Automatic URL prefix** — `Storage::url('path/to/file')` now returns `/assets/pcloud/path/to/file` (the proxy path) by default, with no extra configuration required. The `url` key in your disk config is respected if explicitly set.

### Fixed

- `getUrl()` and `getTemporaryUrl()` now pass `forcedownload=0` to `getpublinkdownload`, so the `Content-Type` is preserved for inline rendering (images and videos display in the browser instead of triggering a download dialog).

**Full Changelog**: https://github.com/leobsst/laravel-pcloud-filesystem/compare/v1.0.3...v1.0.4

## v1.0.3 - 2026-04-23

### Fixed

- `getUrl()` and `getTemporaryUrl()` now return a direct file URL instead of the pCloud viewer page (`e.pcloud.link/publink/show`). The fix chains `getfilepublink` with `getpublinkdownload` to resolve the actual downloadable file URL (`https://host/path`).

**Full Changelog**: https://github.com/leobsst/laravel-pcloud-filesystem/compare/v1.0.2...v1.0.3

## v1.0.2 - 2026-04-23

### What's changed

- **Public URL support** — `Storage::disk('pcloud')->url('path/to/file')` now resolves via pCloud's `getfilepublink` API instead of throwing a `RuntimeException`.
- **Temporary URL support** — `Storage::disk('pcloud')->temporaryUrl('path/to/file', $expiration)` generates an expiring public link by passing the expiration Unix timestamp to `getfilepublink`.

**Full Changelog**: https://github.com/leobsst/laravel-pcloud-filesystem/compare/v1.0.1...v1.0.2

## v1.0.1 - 2026-04-23

### What's changed

* Handle European API in authorization flow

**Full Changelog**: https://github.com/leobsst/laravel-pcloud-filesystem/compare/v1.0.0...v1.0.1

## v1.0.0 — First Release! - 2026-04-23

### Features

- **pCloud filesystem driver** — registers a `pcloud` disk driver that integrates with `Storage::disk('pcloud')` via Flysystem v3 and the official pCloud PHP SDK
- **Full filesystem support** — `put`, `get`, `delete`, `move`, `copy`, `makeDirectory`, `deleteDirectory`, `listContents` (shallow and recursive), `exists`, `directoryExists`, `size`, `lastModified`, `mimeType`
- **Chunked upload** — writes and streams are uploaded using pCloud's chunked upload protocol (10 MB parts), supporting both string content and PHP streams
- **Auto directory creation** — missing intermediate directories are created automatically on write
- **Root scoping** — a configurable `root` option scopes all operations to a specific pCloud folder
- **US / EU datacenter support** — configurable via `location_id` (1 = US, 2 = EU)
- **`pcloud-filesystem:configure` command** — interactive Artisan command that walks through the OAuth2 authorization flow and writes credentials directly to `.env`; supports an optional env variable prefix for multi-disk setups and prompts before overriding existing values
- **`pcloud-filesystem:install` command** — runs the configure flow then prompts to star the repo on GitHub

### Requirements

- PHP 8.2+
- Laravel 11, 12, or 13
