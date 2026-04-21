<?php

namespace Leobsst\LaravelPcloudFilesystem\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Leobsst\LaravelPcloudFilesystem\LaravelPcloudFilesystem
 */
class LaravelPcloudFilesystem extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Leobsst\LaravelPcloudFilesystem\LaravelPcloudFilesystem::class;
    }
}
