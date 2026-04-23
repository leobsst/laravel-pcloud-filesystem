<?php

declare(strict_types=1);

namespace Leobsst\LaravelPcloudFilesystem\Support;

use RuntimeException;

class EnvWriter
{
    /**
     * @param  array<string, string>  $vars
     * @return array{written: list<string>, skipped: list<string>}
     */
    public function write(string $envPath, array $vars, bool $overwrite = false): array
    {
        $contents = @file_get_contents($envPath);

        if ($contents === false) {
            throw new RuntimeException('Could not read .env file at ' . $envPath);
        }

        $written = [];
        $skipped = [];

        foreach ($vars as $key => $value) {
            $escaped = $this->escapeValue($value);
            $pattern = '/^' . preg_quote($key, '/') . '=.*/m';

            if (preg_match($pattern, $contents)) {
                if (! $overwrite && $this->readValue($contents, $key) !== '') {
                    $skipped[] = $key;

                    continue;
                }

                $contents = preg_replace($pattern, $key . '=' . $escaped, $contents);
            } else {
                $contents = rtrim((string) $contents) . "\n" . $key . '=' . $escaped . "\n";
            }

            $written[] = $key;
        }

        file_put_contents($envPath, $contents);

        return ['written' => $written, 'skipped' => $skipped];
    }

    private function readValue(string $contents, string $key): string
    {
        if (preg_match('/^' . preg_quote($key, '/') . '=(.*)$/m', $contents, $matches)) {
            return trim($matches[1]);
        }

        return '';
    }

    private function escapeValue(string $value): string
    {
        if (preg_match('/[\s"\'#\\\\]/', $value)) {
            return '"' . addcslashes($value, '"\\') . '"';
        }

        return $value;
    }
}
