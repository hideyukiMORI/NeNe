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

namespace Nene\Xion;

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

    /**
     * Read the configuration file and initialize it.
     *
     * @return void
     */
    final public static function init()
    {
        require_once dirname(__FILE__) . '/../../ini/xSystemIni.php';       // SYSTEM INITIALIZE
        require_once dirname(__FILE__) . '/../../ini/xSiteIni.php';         // SITE INITIALIZE
    }
}
