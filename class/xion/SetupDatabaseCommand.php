<?php

/**
 * AYANE : ayane.co.jp
 * powered by NENE.
 *
 * PHP Version >= 8.4
 *
 * @package   AYANE
 * @author    hideyukiMORI <info@ayane.co.jp>
 * @copyright 2021 AYANE
 * @license   https://opensource.org/licenses/MIT MIT License
 * @link      https://ayane.co.jp/
 */

declare(strict_types=1);

namespace Nene\Xion;

/**
 * CLI command: create schema tables and seed development data.
 *
 * Invoked by `cli/setupDatabase.php`.
 */
class SetupDatabaseCommand extends Command
{
    /**
     * @return array<string, 'none'|'optional'|'required'>
     */
    protected function optionDefinitions(): array
    {
        return [
            'env' => 'optional',
            'yes' => 'none',
        ];
    }

    protected function helpText(): string
    {
        return <<<'TEXT'
Usage:
  php cli/setupDatabase.php [--env=.env] [--yes]

Options:
  --env   Load a dotenv-style file before NeNe initializes configuration.
          Existing process environment variables take priority.
  --yes   Run without the confirmation prompt.
  --help  Show this help.
TEXT;
    }

    /**
     * @param array<string, string|false> $options
     */
    protected function handle(array $options): int
    {
        $envOptionRaw = $options['env'] ?? null;
        $envExplicit  = is_string($envOptionRaw);
        $envPath      = $this->resolveEnvPath($envExplicit ? $envOptionRaw : '.env');

        if ($envExplicit && !is_file($envPath)) {
            $this->error('Specified env file not found: ' . $envPath);
            return 1;
        }

        $loadedEnv = EnvLoader::loadIfExists($envPath);

        Initialize::init();

        $this->line('Welcome NeNe-PHP database setup!');
        $this->line();
        $this->line('Environment file: ' . ($loadedEnv === [] ? 'not loaded' : $envPath));
        $this->line('Database type: ' . DB_TYPE);
        $this->line('Database target: ' . (
            DB_TYPE === 'MySQL'
                ? DB_HOST . ':' . DB_PORT . '/' . DB_NAME
                : DB_DIR . DB_FILE
        ));
        $this->line('This command creates schema tables and inserts the sample admin data when missing.');
        $this->line();

        if (!isset($options['yes']) && !$this->confirm()) {
            $this->line('OK. Bye!');
            return 0;
        }

        try {
            $summary = DatabaseInstaller::install();
            $health  = DatabaseInstaller::health();
        } catch (\Throwable $throwable) {
            $this->error('Database setup failed: ' . $throwable->getMessage());
            return 1;
        }

        $this->line('Database setup completed.');
        $this->line('Tables: ' . implode(', ', $summary['tables']));
        $this->line('Sample account: admin / ' . (getenv('NENE_SAMPLE_ADMIN_PASSWORD') ?: 'admin'));
        $this->line('Health: API=' . $this->boolText((bool)$health['api'])
            . ' DB=' . $this->boolText((bool)$health['database'])
            . ' Schema=' . $this->boolText((bool)$health['schema']));

        return 0;
    }

    /**
     * Resolve a relative env file path from the repository root.
     */
    private function resolveEnvPath(string $path): string
    {
        if ($path === '') {
            return dirname(__DIR__, 2) . '/.env';
        }
        if (str_starts_with($path, '/')) {
            return $path;
        }
        return dirname(__DIR__, 2) . '/' . $path;
    }

    /**
     * Render a boolean value for CLI output.
     */
    private function boolText(bool $value): string
    {
        return $value ? 'OK' : 'NG';
    }
}
