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
     */
    final public function __construct()
    {
        require_once '../../ini/xSystemIni.php';       // SYSTEM INITIALIZE
        require_once '../../ini/xSiteIni.php';         // SITE INITIALIZE
    }
}
