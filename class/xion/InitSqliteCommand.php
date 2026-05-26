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
 * CLI command: initialize the SQLite database for the sample runtime.
 *
 * Invoked by `cli/initSQLite.php`.
 *
 * This command always initializes a SQLite database at the path configured
 * by NENE_DB_DIR + NENE_DB_FILE, regardless of NENE_DB_TYPE. It uses
 * {@see SchemaCompiler} for DDL so the schema stays in sync with
 * {@see SchemaDefinition}.
 *
 * For generic MySQL or SQLite setup (respects NENE_DB_TYPE), prefer
 * `cli/setupDatabase.php` instead.
 */
class InitSqliteCommand extends Command
{
    /**
     * @return array<string, 'none'|'optional'|'required'>
     */
    protected function optionDefinitions(): array
    {
        return ['yes' => 'none'];
    }

    protected function helpText(): string
    {
        return <<<'TEXT'
Usage:
  php cli/initSQLite.php [--yes] [--help]

Options:
  --yes   Run without the confirmation prompt.
  --help  Show this help.

Note:
  This command always targets the SQLite database at NENE_DB_DIR/NENE_DB_FILE,
  regardless of the NENE_DB_TYPE setting.
  For MySQL or type-aware initialization use: php cli/setupDatabase.php
TEXT;
    }

    /**
     * @param array<string, string|false> $options
     */
    protected function handle(array $options): int
    {
        // Always initialize SQLite, regardless of NENE_DB_TYPE.
        // Force SQLite3 before Initialize::init() defines the constants.
        putenv('NENE_DB_TYPE=SQLite3');
        $_ENV['NENE_DB_TYPE'] = 'SQLite3';

        Initialize::init();

        $this->line('Welcome NeNe-PHP CLI!');
        $this->line();
        $this->line('This command initializes the SQLite database used by the fallback sample runtime.');
        $this->line('Target file: ' . DB_DIR . DB_FILE);
        $this->line();

        if (!isset($options['yes']) && !$this->confirm('Do you want to initialize SQLite? (Y/N)')) {
            $this->line('OK. Bye!');
            return 0;
        }

        try {
            DatabaseInstaller::install();
        } catch (\Throwable $exception) {
            $this->error('SQLite initialization failed: ' . $exception->getMessage());
            return 1;
        }

        $this->line('Processing has been completed.');
        return 0;
    }
}
