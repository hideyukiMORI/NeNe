<?php

/**
 * AYANE : ayane.co.jp
 * powered by NENE.
 *
 * PHP Version >= 7.4
 *
 * @package   AYANE
 * @author    hideyukiMORI <info@ayane.co.jp>
 * @copyright 2021 AYANE
 * @license   https://choosealicense.com/no-permission/ NO LICENSE
 * @link      https://ayane.co.jp/
 */

declare(strict_types=1);

// PHP CORE SETTING

// APP SETTING
const VERSION = '0.0.0.1';                              // APPLICATION VERSION
const DEBUG_MODE = 1;                                   // DEBUG MODE 1=ON / 0=OFF
const LOG_LEVEL = 'INFO';                               // EMERGENCY / INFO
const CONNECT = 'sessionConnect';                       // SESSION CONNECTION NAME

// ROOTING
const OWN_DOMAIN = 'localhost';                         // DOMAIN
const URI_ROOT = '/';                                   // ROOT URI
const LAYERS_NUM = 0;                                   // NUMBER OF LAYERS
const LOGOUT_URI = '/';                                 // URI TO MOVE TO AFTER LOGOUT

// DEFINE DIR
define('DIR_ROOT', dirname(dirname(__FILE__)) . '/');   // ROOT DIR
const DOCUMENT_ROOT = DIR_ROOT . 'htdocs/';             // DOCUMENT DIR
const URI_CSS = URI_ROOT . 'css/';                      // CSS URI
const URI_JS = URI_ROOT . 'js/';                        // JS URI
const URI_IMG = 'https://' . OWN_DOMAIN;                // IMAGE DIR URI



// DATABASE CONNECTION SETUP
const DB_TYPE = 'SQLite3';                           // TYPE [MySQL|SQLite3]
const DB_DIR = DIR_ROOT . 'data/';                     // DATABASE DIRECTORY WHEN USING SQLITE3
const DB_FILE = 'nene.db';                           // DATABASE FILE NAME WHEN USING SQLITE3
const DB_USER = 'root';
const DB_PASS = '';
const DB_HOST = 'localhost';
const DB_NAME = 'nene-php';

// DATABASE
const DB_COLUMN_TIMESTAMP = true;
const DB_COLUMN_NAME_CREATED = 'created_at';            // COLUMN NAME OF ROW CREATION DATE
const DB_COLUMN_NAME_UPDATED = 'updated_at';            // COLUMN NAME OF ROW UPDATE DATE
const DB_AUTO_CREATED_STAMP = true;                     // WHETHER TO SET THE CREATION DATE AUTOMATICALLY
const DB_AUTO_UPDATED_STAMP = true;                     // WHETHER TO SET THE UPDATE DATE AUTOMATICALLY
// WORKAROUND WHEN THE COLUMN NAME STARTS WITH A NUMBER FOR SOME REASON.
const DB_NUM_PREFIX = 'numPrefix_';
const DB_IS_PHYSICAL_DELETE = true;                     // WHETHER TO DELETE PHYSICALLY



// OUTPUT
const JSON_OUTPUT = true;                               // JSON OUTPUT
const ERROR_CODE_PATH = DOCUMENT_ROOT . 'message/error_code.js'; // ERROR CODE PATH

// LOG
const LOG_PATH = DIR_ROOT . 'log/';                     // LOGGING PATH
const APP_LOG_PATH = LOG_PATH . 'debug.log';            // APP LOG PATH
const ACCESS_LOG_PATH = LOG_PATH . 'access.log';        // ACCESS LOG PATH
const ERROR_LOG_PATH = LOG_PATH . 'error.log';          // ERROR LOG PATH

// VIEW
const DIR_SMARTY_TEMPLATE = DIR_ROOT . 'view/source';   // SMARTY TEMPLATE
const DIR_SMARTY_COMPILE = DIR_ROOT . 'view/compile';   // SMARTY TEMPLATE COMPILE
const DIR_SMARTY_CONFIG = DIR_ROOT . 'view/config';     // SMARTY CONFIG
const DIR_SMARTY_PLUGINS = DIR_ROOT . 'view/plugins';   // SMARTY PLUGIN
