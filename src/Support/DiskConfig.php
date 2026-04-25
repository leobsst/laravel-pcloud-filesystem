<?php

declare(strict_types=1);

namespace Leobsst\LaravelPcloudFilesystem\Support;

use Leobsst\LaravelPcloudFilesystem\Enums\LocationEnum;

final readonly class DiskConfig
{
    public function __construct(
        public string $name,
        private string $driver,
        public ?string $accessToken,
        public ?LocationEnum $location,
        public ?string $root,
        public ?string $url,
    ) {}

    public static function fromConfig(string $diskName, array $config): self
    {
        return new self(
            name: $diskName,
            driver: $config['driver'] ?? '',
            accessToken: $config['access_token'] ?? '',
            location: isset($config['location_id']) ? self::parseLocation($config['location_id']) : null,
            root: $config['root'] ?? null,
            url: $config['url'] ?? null,
        );
    }

    public function getProxyUrl(): string
    {
        return '/assets/' . str($this->name)->slug()->toString();
    }

    public function isPcloudDisk(): bool
    {
        return $this->driver === 'pcloud';
    }

    public function hasUrl(): bool
    {
        return filled($this->url);
    }

    public function url(): string
    {
        return $this->url ?? $this->getProxyUrl();
    }

    public function isProxyUrl(): bool
    {
        return $this->hasUrl() && $this->url === $this->getProxyUrl();
    }

    public function canDefineProxyRoute(): bool
    {
        return $this->isPcloudDisk() && (! $this->hasUrl() || $this->isProxyUrl());
    }

    public static function parseLocation(LocationEnum | int | string | null $location): ?LocationEnum
    {
        return match (true) {
            $location instanceof LocationEnum => $location,
            \is_int($location) => LocationEnum::tryFrom($location),
            \is_string($location) => LocationEnum::tryFrom((int) $location),
            default => null,
        };
    }
}
