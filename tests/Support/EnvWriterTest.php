<?php

use Leobsst\LaravelPcloudFilesystem\Support\EnvWriter;

function tmpEnv(string $contents = ''): string
{
    $path = tempnam(sys_get_temp_dir(), 'env_test_');
    file_put_contents($path, $contents);

    return $path;
}

describe('EnvWriter', function () {
    describe('write — new keys', function () {
        it('appends a missing key', function () {
            $path = tmpEnv("APP_NAME=Laravel\n");
            $writer = new EnvWriter;

            $result = $writer->write($path, ['PCLOUD_ACCESS_TOKEN' => 'tok123']);

            expect(file_get_contents($path))->toContain('PCLOUD_ACCESS_TOKEN=tok123');
            expect($result['written'])->toBe(['PCLOUD_ACCESS_TOKEN']);
            expect($result['skipped'])->toBeEmpty();

            unlink($path);
        });

        it('appends multiple missing keys', function () {
            $path = tmpEnv('');
            $writer = new EnvWriter;

            $writer->write($path, [
                'PCLOUD_ACCESS_TOKEN' => 'tok',
                'PCLOUD_LOCATION_ID' => '1',
                'PCLOUD_ROOT' => '/',
            ]);

            $contents = file_get_contents($path);
            expect($contents)->toContain('PCLOUD_ACCESS_TOKEN=tok');
            expect($contents)->toContain('PCLOUD_LOCATION_ID=1');
            expect($contents)->toContain('PCLOUD_ROOT=/');

            unlink($path);
        });
    });

    describe('write — existing keys', function () {
        it('skips an existing non-empty key when overwrite is false', function () {
            $path = tmpEnv("PCLOUD_ACCESS_TOKEN=old\n");
            $writer = new EnvWriter;

            $result = $writer->write($path, ['PCLOUD_ACCESS_TOKEN' => 'new'], overwrite: false);

            expect(file_get_contents($path))->toContain('PCLOUD_ACCESS_TOKEN=old');
            expect($result['skipped'])->toBe(['PCLOUD_ACCESS_TOKEN']);
            expect($result['written'])->toBeEmpty();

            unlink($path);
        });

        it('overwrites an existing key when overwrite is true', function () {
            $path = tmpEnv("PCLOUD_ACCESS_TOKEN=old\n");
            $writer = new EnvWriter;

            $result = $writer->write($path, ['PCLOUD_ACCESS_TOKEN' => 'new'], overwrite: true);

            expect(file_get_contents($path))->toContain('PCLOUD_ACCESS_TOKEN=new');
            expect(file_get_contents($path))->not->toContain('old');
            expect($result['written'])->toBe(['PCLOUD_ACCESS_TOKEN']);

            unlink($path);
        });

        it('replaces an empty existing key even without overwrite', function () {
            $path = tmpEnv("PCLOUD_ACCESS_TOKEN=\n");
            $writer = new EnvWriter;

            $result = $writer->write($path, ['PCLOUD_ACCESS_TOKEN' => 'new'], overwrite: false);

            expect(file_get_contents($path))->toContain('PCLOUD_ACCESS_TOKEN=new');
            expect($result['written'])->toBe(['PCLOUD_ACCESS_TOKEN']);

            unlink($path);
        });
    });

    describe('write — value escaping', function () {
        it('quotes values containing spaces', function () {
            $path = tmpEnv('');
            $writer = new EnvWriter;

            $writer->write($path, ['PCLOUD_ROOT' => '/my folder']);

            expect(file_get_contents($path))->toContain('PCLOUD_ROOT="/my folder"');

            unlink($path);
        });

        it('quotes values containing a hash', function () {
            $path = tmpEnv('');
            $writer = new EnvWriter;

            $writer->write($path, ['PCLOUD_ROOT' => '/path#1']);

            expect(file_get_contents($path))->toContain('PCLOUD_ROOT="/path#1"');

            unlink($path);
        });

        it('does not quote plain values', function () {
            $path = tmpEnv('');
            $writer = new EnvWriter;

            $writer->write($path, ['PCLOUD_LOCATION_ID' => '2']);

            expect(file_get_contents($path))->toContain('PCLOUD_LOCATION_ID=2');

            unlink($path);
        });
    });

    describe('write — error handling', function () {
        it('throws when the .env file cannot be read', function () {
            $writer = new EnvWriter;

            expect(fn () => $writer->write('/nonexistent/.env', ['KEY' => 'val']))
                ->toThrow(RuntimeException::class);
        });
    });
});
