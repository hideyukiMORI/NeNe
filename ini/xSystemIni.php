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

/*
 * System-wide configuration for the NeNe runtime.
 *
 * This file is loaded early by the framework bootstrap and exposes legacy
 * global constants used by controllers, models, views, and database classes.
 * Keep the constant names stable unless a dedicated compatibility migration
 * updates every caller.
 *
 * Configuration style:
 * - Use const for static values that are known from this file alone.
 * - Use define() for values that are resolved at runtime, such as paths based
 *   on __DIR__ or values read from environment variables.
 *
 * Runtime mode:
 * - NENE_APP_ENV names the runtime environment.
 * - NENE_APP_DEBUG controls whether detailed PHP errors may be shown in HTTP
 *   responses. Keep it disabled outside trusted local development.
 * - NENE_SESSION_* values make PHP session Cookie behavior explicit. Local
 *   HTTP development keeps Secure disabled; production should enable it behind
 *   HTTPS.
 *
 * Local development:
 * - Docker Compose passes NENE_DB_* values into the application container.
 * - Without those variables, NeNe falls back to the SQLite-oriented defaults
 *   below so the legacy runtime can still boot in a simple local setup.
 */

/*
 * Runtime paths.
 *
 * DIR_ROOT is the project root with a trailing slash. Most other filesystem
 * paths are derived from it, so update this first if the bootstrap layout ever
 * changes.
 */
define('DIR_ROOT', dirname(__DIR__) . '/');

// SQLite database files are stored under the project data directory.
define('DB_DIR', DIR_ROOT . 'data/');

/**
 * Return an environment variable value, or a default when it is not set.
 *
 * Empty strings are treated as "not configured" because Docker Compose and
 * shell overrides often use an empty value by accident. This preserves the
 * previous `getenv(...) ?: $default` behavior while keeping the rule explicit.
 */
$getEnv = static function (string $name, string $default): string {
    $value = getenv($name);

    if ($value === false || $value === '') {
        return $default;
    }

    return $value;
};

/*
 * Application.
 *
 * VERSION is exposed to templates for cache-busting and display. APP_DEBUG is
 * the runtime boolean flag; DEBUG_MODE remains the legacy integer equivalent
 * because templates and framework code expect 1 or 0.
 */
const VERSION = '0.0.0.1';
define('APP_ENV', $getEnv('NENE_APP_ENV', 'development'));
define('APP_DEBUG', in_array(strtolower($getEnv('NENE_APP_DEBUG', APP_ENV === 'production' ? '0' : '1')), [
    '1',
    'true',
    'on',
    'yes',
], true));
define('DEBUG_MODE', APP_DEBUG ? 1 : 0); // 1 = on, 0 = off.
const LOG_LEVEL = 'INFO'; // EMERGENCY or INFO.

/*
 * Session.
 *
 * CONNECT is the namespace used by the original framework session connection.
 * The Cookie attributes below are applied before session_start() in the public
 * front controller.
 */
const CONNECT = 'sessionConnect';
define('SESSION_COOKIE_SECURE', in_array(strtolower($getEnv('NENE_SESSION_SECURE', APP_ENV === 'production' ? '1' : '0')), [
    '1',
    'true',
    'on',
    'yes',
], true));
define('SESSION_COOKIE_HTTPONLY', in_array(strtolower($getEnv('NENE_SESSION_HTTPONLY', '1')), [
    '1',
    'true',
    'on',
    'yes',
], true));
define('SESSION_COOKIE_SAMESITE', $getEnv('NENE_SESSION_SAMESITE', 'Lax'));
define('SESSION_COOKIE_LIFETIME', (int)$getEnv('NENE_SESSION_LIFETIME', '0'));

/*
 * Routing.
 *
 * URI_ROOT is the public URI prefix for assets and redirects. LAYERS_NUM is
 * used by the legacy URL parser when the app is served from nested path
 * segments. Keep it at 0 for the Docker root deployment.
 */
const OWN_DOMAIN = 'localhost';
const URI_ROOT = '/';
const LAYERS_NUM = 0;
const LOGOUT_URI = '/';

/*
 * Public assets.
 *
 * DOCUMENT_ROOT points to htdocs, which is the public web root. URI_CSS and
 * URI_JS are URL prefixes, not filesystem paths. URI_IMG is kept for legacy
 * template compatibility.
 */
const DOCUMENT_ROOT = DIR_ROOT . 'htdocs/';
const URI_CSS = URI_ROOT . 'css/';
const URI_JS = URI_ROOT . 'js/';
const URI_IMG = 'https://' . OWN_DOMAIN;

/*
 * Database connection.
 *
 * Docker Compose normally sets these values to MySQL. The defaults are kept
 * intentionally conservative for the old SQLite-based boot path:
 * - DB_TYPE accepts MySQL or SQLite3.
 * - DB_FILE is used only when DB_TYPE is SQLite3.
 * - DB_HOST, DB_PORT, DB_NAME, DB_USER, and DB_PASS are used for MySQL.
 *
 * Do not put secrets in this file. Use `.env` for local Docker values and keep
 * `.env.example` updated when adding new environment variables.
 */
define('DB_TYPE', $getEnv('NENE_DB_TYPE', 'SQLite3'));
define('DB_FILE', $getEnv('NENE_DB_FILE', 'nene.db'));
define('DB_USER', $getEnv('NENE_DB_USER', 'root'));
define('DB_PASS', $getEnv('NENE_DB_PASS', ''));
define('DB_HOST', $getEnv('NENE_DB_HOST', 'localhost'));
define('DB_PORT', $getEnv('NENE_DB_PORT', '3306'));
define('DB_NAME', $getEnv('NENE_DB_NAME', 'nene-php'));

unset($getEnv);

/*
 * Database behavior.
 *
 * These flags describe framework conventions used by DataMapperBase. They are
 * not database connection settings:
 * - DB_COLUMN_TIMESTAMP controls whether timestamp columns are included.
 * - DB_AUTO_* controls whether insert/update SQL sets timestamps automatically.
 * - DB_NUM_PREFIX is a workaround for column names that cannot be represented
 *   directly as PHP property names.
 * - DB_IS_PHYSICAL_DELETE chooses physical deletes instead of soft deletes.
 */
const DB_COLUMN_TIMESTAMP = true;
const DB_COLUMN_NAME_CREATED = 'created_at';
const DB_COLUMN_NAME_UPDATED = 'updated_at';
const DB_AUTO_CREATED_STAMP = true;
const DB_AUTO_UPDATED_STAMP = true;
const DB_NUM_PREFIX = 'numPrefix_';
const DB_IS_PHYSICAL_DELETE = true;

/*
 * Output and error catalog.
 *
 * JSON_OUTPUT keeps API responses in JSON mode. ERROR_CODE_PATH is the
 * server-side catalog used by ErrorCode and ApiResponse, and is the source of
 * truth for API error messages and HTTP status values.
 */
const JSON_OUTPUT = true;
const ERROR_CODE_PATH = DIR_ROOT . 'config/error_codes.php';

/*
 * Logs.
 *
 * The Docker init script creates this directory and grants write permissions
 * for the web server user. If logs fail in local development, check directory
 * ownership before changing these constants.
 */
const LOG_PATH = DIR_ROOT . 'log/';
const APP_LOG_PATH = LOG_PATH . 'debug.log';
const ACCESS_LOG_PATH = LOG_PATH . 'access.log';
const ERROR_LOG_PATH = LOG_PATH . 'error.log';

/*
 * Smarty view paths.
 *
 * DIR_SMARTY_TEMPLATE contains source templates. DIR_SMARTY_COMPILE must be
 * writable because Smarty generates compiled PHP templates there at runtime.
 */
const DIR_SMARTY_TEMPLATE = DIR_ROOT . 'view/source';
const DIR_SMARTY_COMPILE = DIR_ROOT . 'view/compile';
const DIR_SMARTY_CONFIG = DIR_ROOT . 'view/config';
const DIR_SMARTY_PLUGINS = DIR_ROOT . 'view/plugins';
