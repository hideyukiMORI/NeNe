<?php

namespace Nene\Xion;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

/**
 * AYANE : ayane.co.jp
 * powered by NENE.
 *
 * @author hideyuki MORI
 */
class Log
{
    private static $instance;
    public $logger;

    /**
     * CONSTRUCTOR
     */
    final private function __construct()
    {
        $this->logger = new Logger('Nene');
        if (LOG_LEVEL == 'EMERGENCY') {
            $this->logger->pushHandler(new StreamHandler(APP_LOG_PATH, Logger::EMERGENCY));
        } else {
            $this->logger->pushHandler(new StreamHandler(APP_LOG_PATH, Logger::INFO));
        }
    }



    /**
     * GET INSTANCE
     */
    final public static function getInstance()
    {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance->logger;
    }



    /**
     * Copy inhibit
     */
    final public function __clone()
    {
        throw new \RuntimeException('Clone is not allowed against ' . get_class($this));
    }
}
