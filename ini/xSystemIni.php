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
 * @license   https://choosealicense.com/no-permission/ NO LICENSE
 * @link      https://ayane.co.jp/
 */

declare(strict_types=1);

// Use const for static configuration values. Use define() for runtime values,
// such as filesystem paths and environment-based settings.

// Runtime paths.
define('DIR_ROOT', dirname(__DIR__) . '/');
define('DB_DIR', DIR_ROOT . 'data/');

/**
 * Return an environment variable value, or a default when it is not set.
 */
$getEnv = static function (string $name, string $default): string {
    $value = getenv($name);

    if ($value === false || $value === '') {
        return $default;
    }

    return $value;
};

// Application.
const VERSION = '0.0.0.1';
const DEBUG_MODE = 1; // 1 = on, 0 = off.
const LOG_LEVEL = 'INFO'; // EMERGENCY or INFO.
const CONNECT = 'sessionConnect';

// Routing.
const OWN_DOMAIN = 'localhost';
const URI_ROOT = '/';
const LAYERS_NUM = 0;
const LOGOUT_URI = '/';

// Public assets.
const DOCUMENT_ROOT = DIR_ROOT . 'htdocs/';
const URI_CSS = URI_ROOT . 'css/';
const URI_JS = URI_ROOT . 'js/';
const URI_IMG = 'https://' . OWN_DOMAIN;

// Database connection.
define('DB_TYPE', $getEnv('NENE_DB_TYPE', 'SQLite3'));
define('DB_FILE', $getEnv('NENE_DB_FILE', 'nene.db'));
define('DB_USER', $getEnv('NENE_DB_USER', 'root'));
define('DB_PASS', $getEnv('NENE_DB_PASS', ''));
define('DB_HOST', $getEnv('NENE_DB_HOST', 'localhost'));
define('DB_PORT', $getEnv('NENE_DB_PORT', '3306'));
define('DB_NAME', $getEnv('NENE_DB_NAME', 'nene-php'));

unset($getEnv);

// Database behavior.
const DB_COLUMN_TIMESTAMP = true;
const DB_COLUMN_NAME_CREATED = 'created_at';
const DB_COLUMN_NAME_UPDATED = 'updated_at';
const DB_AUTO_CREATED_STAMP = true;
const DB_AUTO_UPDATED_STAMP = true;
const DB_NUM_PREFIX = 'numPrefix_';
const DB_IS_PHYSICAL_DELETE = true;

// Output and error catalog.
const JSON_OUTPUT = true;
const ERROR_CODE_PATH = DIR_ROOT . 'config/error_codes.php';

// Logs.
const LOG_PATH = DIR_ROOT . 'log/';
const APP_LOG_PATH = LOG_PATH . 'debug.log';
const ACCESS_LOG_PATH = LOG_PATH . 'access.log';
const ERROR_LOG_PATH = LOG_PATH . 'error.log';

// Smarty view paths.
const DIR_SMARTY_TEMPLATE = DIR_ROOT . 'view/source';
const DIR_SMARTY_COMPILE = DIR_ROOT . 'view/compile';
const DIR_SMARTY_CONFIG = DIR_ROOT . 'view/config';
const DIR_SMARTY_PLUGINS = DIR_ROOT . 'view/plugins';
