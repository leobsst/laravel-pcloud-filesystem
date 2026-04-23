<?php

declare(strict_types=1);

namespace Leobsst\LaravelPcloudFilesystem\Support;

final readonly class OAuthResult
{
    public function __construct(
        public string $accessToken,
        public int $locationId,
    ) {}
}
