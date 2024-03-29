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

use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;

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
     * @var [type]
     */
    private static $instance;

    /**
     * Logger class for access log
     *
     * @var [type]
     */
    public $accessLog;

    /**
     * Logger class for information log
     *
     * @var [type]
     */
    public $informationLog;

    /**
     * Logger class for error log
     *
     * @var [type]
     */
    public $errorLog;

    /**
     * CONSTRUCTOR
     */
    final private function __construct()
    {
        $this->accessLog = new Logger('Nene');
        $this->accessLog->pushHandler(new RotatingFileHandler(ACCESS_LOG_PATH, 60, Logger::INFO));
        $this->informationLog = new Logger('Nene');
        $this->errorLog = new Logger('Nene');
        $this->errorLog->pushHandler(new RotatingFileHandler(ERROR_LOG_PATH, 60, Logger::ERROR));
        if (LOG_LEVEL == 'EMERGENCY') {
            // $this->logger->pushHandler(new StreamHandler(APP_LOG_PATH, Logger::EMERGENCY));
            $this->informationLog->pushHandler(new RotatingFileHandler(APP_LOG_PATH, 100, Logger::EMERGENCY));
        } else {
            // $this->logger->pushHandler(new StreamHandler(APP_LOG_PATH, Logger::INFO));
            $this->informationLog->pushHandler(new RotatingFileHandler(APP_LOG_PATH, 100, Logger::INFO));
        }
    }

    /**
     * GET INSTANCE
     *
     * @param string $mode Log type.
     *
     * @return Logger
     */
    final public static function getInstance(string $mode = 'information')
    {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }
        switch ($mode) {
            case 'information':
                return self::$instance->informationLog;
                break;
            case 'error':
                return self::$instance->errorLog;
                break;
            default:
                return self::$instance->accessLog;
                break;
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
