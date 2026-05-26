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

use Nene\Xion\SetupDatabaseCommand;

require_once dirname(__DIR__) . '/vendor/autoload.php';

ini_set('display_errors', '1');
error_reporting(E_ALL);
date_default_timezone_set('Asia/Tokyo');

exit((new SetupDatabaseCommand())->execute());
