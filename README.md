# Laravel pCloud Filesystem

[![Latest Version on Packagist](https://img.shields.io/packagist/v/leobsst/laravel-pcloud-filesystem.svg?style=flat-square)](https://packagist.org/packages/leobsst/laravel-pcloud-filesystem)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/leobsst/laravel-pcloud-filesystem/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/leobsst/laravel-pcloud-filesystem/actions?query=workflow%3Arun-tests+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/leobsst/laravel-pcloud-filesystem.svg?style=flat-square)](https://packagist.org/packages/leobsst/laravel-pcloud-filesystem)
[![License](https://img.shields.io/badge/license-MIT-green.svg
)](https://opensource.org/licenses/MIT)
[![Laravel](https://img.shields.io/badge/Laravel-11.0%20|%2012.0%20|%2013.0-FF2D20?logo=laravel)](https://laravel.com)
[![PHP](https://img.shields.io/badge/PHP-8.2+-777BB4?logo=php)](https://www.php.net)

A [Laravel](https://laravel.com) filesystem driver for [pCloud](https://www.pcloud.com), built on top of the [pCloud PHP SDK](https://github.com/pCloud/pcloud-sdk-php) and [Flysystem v3](https://flysystem.thephpleague.com). Exposes a `pcloud` disk driver that integrates seamlessly with `Storage::disk('pcloud')`.

## Requirements

- PHP 8.2+
- Laravel 11, 12, or 13

## Installation

Install the package via Composer:

```bash
composer require leobsst/laravel-pcloud-filesystem
```

The service provider is auto-discovered — no manual registration needed.

## Configuration

### 1. Obtain a pCloud access token

Generate a token in your pCloud developer console or via the pCloud PHP SDK OAuth2 flow.

### 2. Add environment variables

Add the following to your `.env` file:

```env
PCLOUD_ACCESS_TOKEN=your-access-token-here
PCLOUD_LOCATION_ID=1     # 1 = US servers, 2 = EU servers
PCLOUD_ROOT=/            # Optional: root folder for all operations
```

### 3. Register the disk

Add the `pcloud` entry to the `disks` array in `config/filesystems.php`:

```php
'disks' => [
    // ...existing disks...

    'pcloud' => [
        'driver'       => 'pcloud',
        'access_token' => env('PCLOUD_ACCESS_TOKEN'),
        'location_id'  => env('PCLOUD_LOCATION_ID', 1),
        'root'         => env('PCLOUD_ROOT', '/'),
    ],
],
```

The `root` option scopes all filesystem operations to that pCloud folder. For example, setting `root` to `/MyApp` means `Storage::disk('pcloud')->put('uploads/file.txt', ...)` will write to `/MyApp/uploads/file.txt` on pCloud. Missing intermediate directories are created automatically.

## Usage

Once configured, use the disk exactly like any other Laravel filesystem disk:

```php
use Illuminate\Support\Facades\Storage;

// Write a file
Storage::disk('pcloud')->put('hello.txt', 'Hello, pCloud!');

// Write from a stream
Storage::disk('pcloud')->writeStream('video.mp4', fopen('/path/to/video.mp4', 'rb'));

// Check existence
Storage::disk('pcloud')->exists('hello.txt');       // true
Storage::disk('pcloud')->directoryExists('photos'); // true

// Read a file
$contents = Storage::disk('pcloud')->get('hello.txt');

// Read as a stream
$stream = Storage::disk('pcloud')->readStream('video.mp4');

// List contents (non-recursive)
$files = Storage::disk('pcloud')->files('photos');

// List contents recursively
$all = Storage::disk('pcloud')->allFiles('photos');

// Move / rename
Storage::disk('pcloud')->move('hello.txt', 'archive/hello.txt');

// Copy
Storage::disk('pcloud')->copy('hello.txt', 'backup/hello.txt');

// Delete a file
Storage::disk('pcloud')->delete('hello.txt');

// Delete a directory and its contents
Storage::disk('pcloud')->deleteDirectory('archive');

// Create a directory
Storage::disk('pcloud')->makeDirectory('new-folder');

// File metadata
Storage::disk('pcloud')->size('video.mp4');
Storage::disk('pcloud')->lastModified('video.mp4');
Storage::disk('pcloud')->mimeType('video.mp4');
```

## Unsupported features

**Visibility control** is not supported by pCloud. Calling `setVisibility()` always throws `UnableToSetVisibility`. The `visibility()` method always returns `public`.

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](.github/CONTRIBUTING.md) for details.

## Security

Please see [SECURITY](.github/SECURITY.md) for how to report security vulnerabilities.

## Credits

- [LEOBSST](https://github.com/leobsst)
- [B.L.A.M. PRODUCTION](https://blam-prod.fr)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
