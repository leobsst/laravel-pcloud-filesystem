<?php

declare(strict_types=1);

namespace Leobsst\LaravelPcloudFilesystem\Support;

use Illuminate\Support\Facades\Http;
use Leobsst\LaravelPcloudFilesystem\Enums\LocationEnum;
use RuntimeException;

class PcloudOAuthClient
{
    private const AUTHORIZE_URL = 'https://my.pcloud.com/oauth2/authorize';

    public function getAuthorizeUrl(string $clientId): string
    {
        return self::AUTHORIZE_URL . '?response_type=code&client_id=' . urlencode($clientId);
    }

    /**
     * @throws RuntimeException
     */
    public function fetchToken(string $clientId, string $clientSecret, string $code, LocationEnum $location): OAuthResult
    {
        $response = Http::get($location->tokenUrl(), [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'code' => $code,
        ]);

        if ($response->failed()) {
            throw new RuntimeException('HTTP request to pCloud token endpoint failed.');
        }

        $data = $response->json();

        if (! \is_array($data)) {
            throw new RuntimeException('Invalid JSON response from pCloud token endpoint.');
        }

        if (! isset($data['access_token'])) {
            $error = $data['error'] ?? ('Unknown error (result=' . ($data['result'] ?? '?') . ')');

            throw new RuntimeException('pCloud OAuth error: ' . $error);
        }

        return new OAuthResult(
            accessToken: (string) $data['access_token'],
            location: $location,
        );
    }
}
