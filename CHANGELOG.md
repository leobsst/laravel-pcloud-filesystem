# Changelog

All notable changes to `laravel-pcloud-filesystem` will be documented in this file.

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
