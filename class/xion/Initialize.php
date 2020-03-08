<?php
namespace Nene\Xion;

/**
 * AYANE : ayane.co.jp
 * powerd by NENE.
 *
 * @author hideyuki MORI
 */

/**
 * Initialize the settings
 */

class Initialize
{
    /**
     * CONSTRUCTOR
     * Load various definition files.
     */
    final public function __construct()
    {
    }



    final public static function setIni() {
        require_once '../ini/xSystemIni.php';       // SYSTEM INITIALIZE
        require_once '../ini/xSiteIni.php';         // SITE INITIALIZE
    }



}
