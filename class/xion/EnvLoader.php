<?php

declare(strict_types=1);

namespace Nene\Xion;

/**
 * Minimal dotenv-style loader for CLI setup commands.
 */
final class EnvLoader
{
    /**
     * Load KEY=VALUE lines from a local env file when it exists.
     *
     * Existing process environment values win over file values so server-level
     * configuration can override local files.
     *
     * @return array<string,string> Loaded values keyed by environment name.
     */
    public static function loadIfExists(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $loaded = [];
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            throw new \RuntimeException('Environment file could not be read: ' . $path);
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            [$name, $value] = self::parseLine($line);
            if ($name === '' || getenv($name) !== false) {
                continue;
            }

            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
            $loaded[$name] = $value;
        }

        return $loaded;
    }

    /**
     * Parse one dotenv-like line.
     *
     * @return array{0:string,1:string}
     */
    private static function parseLine(string $line): array
    {
        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            return ['', ''];
        }

        $name = trim($parts[0]);
        $value = trim($parts[1]);
        if (
            strlen($value) >= 2
            && (($value[0] === '"' && substr($value, -1) === '"')
                || ($value[0] === "'" && substr($value, -1) === "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        return [$name, $value];
    }
}
