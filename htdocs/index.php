<?php
namespace Nene;

use Nene\Xion as Xion;

ini_set('display_errors', '1');             // DISPLAY ERROR
error_reporting(E_ALL);                     // ERROR REPORT
session_cache_expire(180);                  // SESSION => 3H
date_default_timezone_set('Asia/Tokyo');    // TIME ZONE
session_start();                            // SESSION START

/**
 * AYANE : ayane.co.jp
 * powerd by NENE.
 *
 * @author hideyuki MORI
 */

/**
 * This file is the entrance of the front controller model.
 * This file accepts all access to the controller.
 *
 * @author HideyukiMORI
 */
require_once '../vendor/autoload.php';       // AUTO LOAD

Xion\Initialize::init();
$dispatcher = new Xion\Dispatcher();
$dispatcher->dispatch();
exit();
