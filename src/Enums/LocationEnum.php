<?php

declare(strict_types=1);

namespace Leobsst\LaravelPcloudFilesystem\Enums;

enum LocationEnum: int
{
    case US = 1;
    case EU = 2;

    public function tokenUrl(): string
    {
        return match ($this) {
            self::US => 'https://api.pcloud.com/oauth2_token',
            self::EU => 'https://eapi.pcloud.com/oauth2_token',
        };
    }

    public function name(): string
    {
        return match ($this) {
            self::US => 'United States',
            self::EU => 'Europe',
        };
    }
}
