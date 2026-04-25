<?php

use Illuminate\Support\Facades\Http;
use Leobsst\LaravelPcloudFilesystem\Enums\LocationEnum;
use Leobsst\LaravelPcloudFilesystem\Support\OAuthResult;
use Leobsst\LaravelPcloudFilesystem\Support\PcloudOAuthClient;

describe('PcloudOAuthClient', function () {
    describe('getAuthorizeUrl', function () {
        it('returns the pCloud authorize URL with client_id and response_type', function () {
            $client = new PcloudOAuthClient;
            $url = $client->getAuthorizeUrl('my-client-id');

            expect($url)->toStartWith('https://my.pcloud.com/oauth2/authorize?');
            expect($url)->toContain('response_type=code');
            expect($url)->toContain('client_id=my-client-id');
        });

        it('URL-encodes the client_id', function () {
            $client = new PcloudOAuthClient;
            $url = $client->getAuthorizeUrl('id with spaces');

            expect($url)->toContain('client_id=id+with+spaces');
        });
    });

    describe('fetchToken', function () {
        it('returns an OAuthResult on a successful response', function () {
            Http::fake([
                'api.pcloud.com/oauth2_token*' => Http::response([
                    'result' => 0,
                    'access_token' => 'tok_abc',
                    'token_type' => 'bearer',
                    'locationid' => 2,
                    'uid' => 99,
                ]),
            ]);

            $result = (new PcloudOAuthClient)->fetchToken('cid', 'csecret', 'authcode', location: LocationEnum::EU);

            expect($result)->toBeInstanceOf(OAuthResult::class);
            expect($result->accessToken)->toBe('tok_abc');
            expect($result->location)->toBe(LocationEnum::EU);
        });

        it('defaults location to 1 when absent from response', function () {
            Http::fake([
                'api.pcloud.com/oauth2_token*' => Http::response([
                    'result' => 0,
                    'access_token' => 'tok',
                ]),
            ]);

            $result = (new PcloudOAuthClient)->fetchToken('cid', 'csecret', 'code', location: LocationEnum::US);

            expect($result->location)->toBe(LocationEnum::US);
        });

        it('uses the US endpoint when location is 1', function () {
            Http::fake([
                'api.pcloud.com/oauth2_token*' => Http::response(['result' => 0, 'access_token' => 'tok']),
            ]);

            (new PcloudOAuthClient)->fetchToken('cid', 'csecret', 'code', location: LocationEnum::US);

            Http::assertSent(fn ($request) => str_contains($request->url(), 'api.pcloud.com'));
        });

        it('uses the EU endpoint when location is 2', function () {
            Http::fake([
                'eapi.pcloud.com/oauth2_token*' => Http::response(['result' => 0, 'access_token' => 'tok']),
            ]);

            (new PcloudOAuthClient)->fetchToken('cid', 'csecret', 'code', location: LocationEnum::EU);

            Http::assertSent(fn ($request) => str_contains($request->url(), 'eapi.pcloud.com'));
        });

        it('falls back to the US endpoint for an unknown location', function () {
            Http::fake([
                'api.pcloud.com/oauth2_token*' => Http::response(['result' => 0, 'access_token' => 'tok']),
            ]);

            (new PcloudOAuthClient)->fetchToken('cid', 'csecret', 'code', location: LocationEnum::US);

            Http::assertSent(fn ($request) => str_contains($request->url(), 'api.pcloud.com'));
        });

        it('throws RuntimeException on HTTP failure', function () {
            Http::fake([
                'api.pcloud.com/oauth2_token*' => Http::response(null, 500),
            ]);

            expect(fn () => (new PcloudOAuthClient)->fetchToken('cid', 'csecret', 'code', location: LocationEnum::US))
                ->toThrow(RuntimeException::class, 'HTTP request');
        });

        it('throws RuntimeException when access_token is missing', function () {
            Http::fake([
                'api.pcloud.com/oauth2_token*' => Http::response([
                    'result' => 2000,
                    'error' => 'Invalid client credentials.',
                ]),
            ]);

            expect(fn () => (new PcloudOAuthClient)->fetchToken('cid', 'csecret', 'code', location: LocationEnum::US))
                ->toThrow(RuntimeException::class, 'Invalid client credentials.');
        });

        it('passes client_id, client_secret and code to the endpoint', function () {
            Http::fake([
                'api.pcloud.com/oauth2_token*' => Http::response([
                    'result' => 0,
                    'access_token' => 'tok',
                ]),
            ]);

            (new PcloudOAuthClient)->fetchToken('my-id', 'my-secret', 'my-code', location: LocationEnum::US);

            Http::assertSent(function ($request) {
                return str_contains($request->url(), 'client_id=my-id')
                    && str_contains($request->url(), 'client_secret=my-secret')
                    && str_contains($request->url(), 'code=my-code');
            });
        });
    });
});
