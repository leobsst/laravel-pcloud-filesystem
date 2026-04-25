<?php

declare(strict_types=1);

namespace Leobsst\LaravelPcloudFilesystem\Support;

use Leobsst\LaravelPcloudFilesystem\Enums\LocationEnum;

final readonly class OAuthResult
{
    public function __construct(
        public string $accessToken,
        public LocationEnum $location,
    ) {}
}
