<?php

declare(strict_types=1);

namespace Nene;

use Nene\Xion as Xion;

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

/**
 * AYANE : ayane.co.jp
 * powered by NENE.
 *
 * @author hideyuki MORI
 */

/**
 * This file is the entrance of the front controller model.
 * This file accepts all access to the controller.
 *
 * @author HideyukiMORI
 */
require_once '../vendor/autoload.php';

Xion\Initialize::init();

configurePublicErrorDisplay();
session_cache_expire(180);
date_default_timezone_set('Asia/Tokyo');
session_start();

$dispatcher = new Xion\Dispatcher();
$dispatcher->dispatch();
exit();

/**
 * Configure whether PHP errors can be shown in HTTP responses.
 */
function configurePublicErrorDisplay(): void
{
    ini_set('display_errors', APP_DEBUG ? '1' : '0');
    ini_set('display_startup_errors', APP_DEBUG ? '1' : '0');
}
