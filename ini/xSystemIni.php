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
 * @link      https://konyacorp.com/
 */

// PHP CORE SETTING

// APP SETTING
define('VERSION', '0.0.0.1');                           // APPLICATION VERSION
define('DUBUG_MODE', 1);                                // DEBUG MODE 1=ON / 0=OFF
define('LOG_LEVEL', 'INFO');                            // EMERGENCY / INFO
define('CONNECT', 'sessionConnect');                    // SESSION CONNECTION NAME

// ROOTING
define('OWN_DOMAIN', 'localhost');                      // DOMAIN
define('URI_ROOT', '/');                                // ROOT URI
define('LAYERS_NUM', 0);                                // NUMBER OF LAYERS
define('LOGOUT_URI', '/');                              // URI TO MOVE TO AFTER LOGOUT

// DEFINE DIR
define('DIR_ROOT', dirname(dirname(__FILE__)).'/');     // ROOT DIR
define('DOCUMENT_ROOT', DIR_ROOT.'htdocs/');            // DOCUMENT DIR
define('URI_CSS', URI_ROOT.'css/');                     // CSS URI
define('URI_JS', URI_ROOT.'js/');                       // JS URI
define('URI_IMG', 'https://'.OWN_DOMAIN);               // IMAGE DIR URI



// DATABASE CONNECTION SETUP
define('DB_TYPE', 'SQLite3');                           // TYPE [MySQL|SQLite3]
define('DB_DIR', DIR_ROOT.'data/');                     // DATABASE DIRECTORY WHEN USING SQLITE3
define('DB_FILE', 'nene.db');                           // DATABASE FILE NAME WHEN USING SQLITE3
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_HOST', 'localhost');
define('DB_NAME', 'nene-php');

// DATABASE
define('DB_COLUMN_NAME_CREATED', 'created_at');         // COLUMN NAME OF ROW CREATION DATE
define('DB_COLUMN_NAME_UPDATED', 'updated_at');         // COLUMN NAME OF ROW UPDATE DATE
define('DB_AUTO_CREATED_STAMP', true);                  // WHETHER TO SET THE CREATION DATE AUTOMATICALLY
define('DB_AUTO_UPDATED_STAMP', true);                  // WHETHER TO SET THE UPDATE DATE AUTOMATICALLY
define('DB_NUM_PREFIX', 'numPrefix_');                  // WORKAROUND WHEN THE COLUMN NAME STARTS WITH A NUMBER FOR SOME REASON.
define('DB_IS_PHYSICAL_DELETE', true);                  // WHETHER TO DELETE PHYSICALLY



// OUTPUT
define('JSON_OUTPUT', true);                            // JSON OUTPUT
define('ERROR_CODE_PATH', DOCUMENT_ROOT.'message/error_code.js'); // ERROR CODE PATH

// LOG
define('LOG_PATH', DIR_ROOT.'log/');                    // LOGGING PATH
define('APP_LOG_PATH', LOG_PATH.'debug.log');           // LOGGING PATH
define('ACCESS_LOG_PATH', LOG_PATH.'access.log');       // ACCESS PATH

// VIEW
define('DIR_SMARTY_TEMPLATE', DIR_ROOT.'view/source');  // SMARTY TEMPLATE
define('DIR_SMARTY_COMPILE', DIR_ROOT.'view/compile');  // SMARTY TEMPLATE COMPILE
define('DIR_SMARTY_CONFIG', DIR_ROOT.'view/config');    // SMARTY CONFIG
