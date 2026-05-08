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

namespace Nene\Xion;

use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;
use Monolog\Logger;

/**
 * LOGGING class
 *
 * A singleton class that returns a monolog object.
 *
 * @author hideyuki MORI
 */
class Log
{
    /**
     * Instance to pass as a singleton.
     *
     * @var Log
     */
    private static $instance;

    /**
     * Logger class for access log
     *
     * @var Logger
     */
    public $accessLog;

    /**
     * Logger class for information log
     *
     * @var Logger
     */
    public $informationLog;

    /**
     * Logger class for error log
     *
     * @var Logger
     */
    public $errorLog;

    /**
     * CONSTRUCTOR
     */
    final private function __construct()
    {
        $this->accessLog = new Logger('Nene');
        $this->accessLog->pushHandler(new RotatingFileHandler(ACCESS_LOG_PATH, 60, Level::Info));
        $this->informationLog = new Logger('Nene');
        $this->errorLog = new Logger('Nene');
        $this->errorLog->pushHandler(new RotatingFileHandler(ERROR_LOG_PATH, 60, Level::Error));
        if (LOG_LEVEL == 'EMERGENCY') {
            $this->informationLog->pushHandler(new RotatingFileHandler(APP_LOG_PATH, 100, Level::Emergency));
        } else {
            $this->informationLog->pushHandler(new RotatingFileHandler(APP_LOG_PATH, 100, Level::Info));
        }
    }

    /**
     * GET INSTANCE
     *
     * @param string $mode Log type.
     *
     * @return Logger
     */
    final public static function getInstance(string $mode = 'information'): Logger
    {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }
        switch ($mode) {
            case 'information':
                return self::$instance->informationLog;
            case 'error':
                return self::$instance->errorLog;
            default:
                return self::$instance->accessLog;
        }
    }

    /**
     * Copy inhibit
     *
     * @return void
     */
    final public function __clone()
    {
        throw new \RuntimeException('Clone is not allowed against ' . get_class($this));
    }
}
