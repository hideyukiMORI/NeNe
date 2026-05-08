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

use Nene\Xion\DatabaseInstaller;
use Nene\Xion\EnvLoader;
use Nene\Xion\Initialize;

require_once dirname(__DIR__) . '/vendor/autoload.php';

ini_set('display_errors', '1');
error_reporting(E_ALL);
date_default_timezone_set('Asia/Tokyo');

$options = getopt('', ['env::', 'yes', 'help']) ?: [];
if (isset($options['help'])) {
    showHelp();
    return 0;
}

$envPath = resolveEnvPath(is_string($options['env'] ?? null) ? $options['env'] : '.env');
$loadedEnv = EnvLoader::loadIfExists($envPath);

Initialize::init();

echo 'Welcome NeNe-PHP database setup!' . PHP_EOL . PHP_EOL;
echo 'Environment file: ' . ($loadedEnv === [] ? 'not loaded' : $envPath) . PHP_EOL;
echo 'Database type: ' . DB_TYPE . PHP_EOL;
echo 'Database target: ' . (DB_TYPE === 'MySQL' ? DB_HOST . ':' . DB_PORT . '/' . DB_NAME : DB_DIR . DB_FILE) . PHP_EOL;
echo 'This command creates users/todos tables and inserts the sample admin data when missing.' . PHP_EOL . PHP_EOL;

if (!isset($options['yes'])) {
    echo 'Do you want to continue? (Y/N)' . PHP_EOL;
    $answer = trim((string)fgets(STDIN));
    if (!in_array(strtolower($answer), ['y', 'yes'], true)) {
        echo 'OK. Bye!' . PHP_EOL;
        return 0;
    }
}

try {
    $summary = DatabaseInstaller::install();
    $health = DatabaseInstaller::health();
} catch (Throwable $throwable) {
    fwrite(STDERR, 'Database setup failed: ' . $throwable->getMessage() . PHP_EOL);
    return 1;
}

echo 'Database setup completed.' . PHP_EOL;
echo 'Tables: ' . implode(', ', $summary['tables']) . PHP_EOL;
echo 'Sample account: admin / ' . (getenv('NENE_SAMPLE_ADMIN_PASSWORD') ?: 'admin') . PHP_EOL;
echo 'Health: API=' . boolText((bool)$health['api'])
    . ' DB=' . boolText((bool)$health['database'])
    . ' Schema=' . boolText((bool)$health['schema']) . PHP_EOL;

return 0;

/**
 * Show command usage.
 */
function showHelp(): void
{
    echo <<<'TEXT'
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
 * Resolve a relative env file path from the repository root.
 */
function resolveEnvPath(string $path): string
{
    if ($path === '') {
        return dirname(__DIR__) . '/.env';
    }

    if (str_starts_with($path, '/')) {
        return $path;
    }

    return dirname(__DIR__) . '/' . $path;
}

/**
 * Render a boolean value for CLI output.
 */
function boolText(bool $value): string
{
    return $value ? 'OK' : 'NG';
}
