<?php

declare(strict_types=1);

namespace Leobsst\LaravelPcloudFilesystem\Commands;

use Illuminate\Console\Command;
use Leobsst\LaravelPcloudFilesystem\Enums\LocationEnum;
use Leobsst\LaravelPcloudFilesystem\Support\EnvWriter;
use Leobsst\LaravelPcloudFilesystem\Support\PcloudOAuthClient;
use RuntimeException;

class ConfigureCommand extends Command
{
    protected $signature = 'pcloud-filesystem:configure
                            {--prefix= : Environment variable prefix (e.g. "MYAPP")}';

    protected $description = 'Configure pCloud filesystem credentials via OAuth2 and write them to .env';

    public function __construct(
        private readonly PcloudOAuthClient $oauthClient,
        private readonly EnvWriter $envWriter,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($this->option('prefix') !== null) {
            $prefix = strtoupper(trim((string) $this->option('prefix'), '_'));
        } else {
            $prefix = $this->ask('Environment variable prefix (leave empty for none, e.g. "MYAPP" → MYAPP_PCLOUD_ACCESS_TOKEN)', '');
            $prefix = strtoupper(trim((string) $prefix, '_'));
        }

        $envPrefix = ! empty($prefix) ? $prefix . '_' : '';

        $clientId = (string) $this->ask('pCloud OAuth2 client_id');
        $clientSecret = (string) $this->ask('pCloud OAuth2 client_secret');

        if (empty($clientId) || empty($clientSecret)) {
            $this->error('client_id and client_secret are required.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('Open the following URL in your browser and authorize the application:');
        $this->newLine();
        $this->line('  ' . $this->oauthClient->getAuthorizeUrl($clientId));
        $this->newLine();
        $this->line('After authorization, pCloud will redirect you. Copy the <info>code</info> and <info>locationid</info> from the redirect URL query string.');
        $this->newLine();

        $code = (string) $this->ask('Paste the authorization code here');

        if (empty($code)) {
            $this->error('Authorization code is required.');

            return self::FAILURE;
        }

        $locationId = (int) $this->ask('Paste the locationid from the redirect URL (1 = US, 2 = EU)', '1');
        $location = LocationEnum::tryFrom($locationId);

        if ($location === null) {
            $this->error('Invalid locationid. Must be 1 (US) or 2 (EU).');

            return self::FAILURE;
        }

        $this->line('Exchanging code for access token...');

        try {
            $result = $this->oauthClient->fetchToken($clientId, $clientSecret, $code, $location);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $root = (string) $this->ask('Root path on pCloud', '/');

        $vars = [
            "{$envPrefix}PCLOUD_ACCESS_TOKEN" => $result->accessToken,
            "{$envPrefix}PCLOUD_LOCATION_ID" => (string) $result->location->value,
            "{$envPrefix}PCLOUD_ROOT" => $root,
        ];

        $envPath = base_path('.env');

        if (! file_exists($envPath)) {
            $this->error('.env file not found at ' . $envPath);

            return self::FAILURE;
        }

        $this->writeWithConfirmation($envPath, $vars);

        $locationEnumClass = LocationEnum::class;

        $this->newLine();
        $this->info('pCloud credentials written to .env successfully!');
        $this->newLine();
        $this->line('Make sure your <comment>config/filesystems.php</comment> disk is configured:');
        $this->newLine();
        $this->line("  'pcloud' => [");
        $this->line("      'driver'       => 'pcloud',");
        $this->line("      'access_token' => env('{$envPrefix}PCLOUD_ACCESS_TOKEN'),");
        $this->line("      'location_id'  => env('{$envPrefix}PCLOUD_LOCATION_ID', {$locationEnumClass}::US),");
        $this->line("      'root'         => env('{$envPrefix}PCLOUD_ROOT', '/'),");
        $this->line('  ],');
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string>  $vars
     */
    private function writeWithConfirmation(string $envPath, array $vars): void
    {
        // First pass: write without overwriting, collect skipped keys
        ['written' => $written, 'skipped' => $skipped] = $this->envWriter->write($envPath, $vars, overwrite: false);

        foreach ($written as $key) {
            $this->line("  Added/updated <info>{$key}</info>.");
        }

        // Second pass: ask confirmation for each already-set key
        foreach ($skipped as $key) {
            if ($this->confirm("  <comment>{$key}</comment> is already set. Override?", false)) {
                $this->envWriter->write($envPath, [$key => $vars[$key]], overwrite: true);
                $this->line("  Updated <info>{$key}</info>.");
            } else {
                $this->line("  Skipped <comment>{$key}</comment>.");
            }
        }
    }
}
