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
     * @var Log|null
     */
    private static ?self $instance = null;

    /**
     * Logger class for access log
     *
     * @var Logger
     */
    public Logger $accessLog;

    /**
     * Logger class for information log
     *
     * @var Logger
     */
    public Logger $informationLog;

    /**
     * Logger class for error log
     *
     * @var Logger
     */
    public Logger $errorLog;

    /**
     * CONSTRUCTOR
     */
    final private function __construct()
    {
        $formatter = LogFormatterFactory::fromConstant();

        $accessHandler = new RotatingFileHandler(ACCESS_LOG_PATH, 60, Level::Info);
        $accessHandler->setFormatter($formatter);
        $this->accessLog = new Logger('Nene');
        $this->accessLog->pushHandler($accessHandler);

        $this->informationLog = new Logger('Nene');
        $this->errorLog = new Logger('Nene');

        $errorHandler = new RotatingFileHandler(ERROR_LOG_PATH, 60, Level::Error);
        $errorHandler->setFormatter($formatter);
        $this->errorLog->pushHandler($errorHandler);

        $infoLevel = LOG_LEVEL == 'EMERGENCY' ? Level::Emergency : Level::Info;
        $infoHandler = new RotatingFileHandler(APP_LOG_PATH, 100, $infoLevel);
        $infoHandler->setFormatter($formatter);
        $this->informationLog->pushHandler($infoHandler);

        // Tag every log record with the per-request request_id so that
        // operators can grep all entries belonging to one request
        // (FT15). The processor reads `RequestId::current()` lazily so
        // it picks up the value resolved on the first call within the
        // request's lifecycle.
        $requestIdProcessor = static function (\Monolog\LogRecord $record): \Monolog\LogRecord {
            return $record->with(extra: array_merge($record->extra, ['request_id' => RequestId::current()]));
        };
        $this->accessLog->pushProcessor($requestIdProcessor);
        $this->informationLog->pushProcessor($requestIdProcessor);
        $this->errorLog->pushProcessor($requestIdProcessor);
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
     * @return never
     */
    final public function __clone(): never
    {
        throw new \RuntimeException('Clone is not allowed against ' . get_class($this));
    }
}
