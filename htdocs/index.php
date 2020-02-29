<?php
namespace Nene;

ini_set('display_errors', 1);               // DISPLAY ERROR
error_reporting(E_ALL);                     // ERROR REPORT
session_cache_expire(180);                  // SESSION => 3H

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

new Xion\Initialize();
$dispatcher = new Xion\Dispatcher();
$dispatcher->dispatch();
exit();
