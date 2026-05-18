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

/**
 * Error code catalog.
 */
class ErrorCode
{
    /**
     * Instance to pass as a singleton.
     *
     * @var ErrorCode|null
     */
    private static ?self $instance = null;

    /**
     * Error code array.
     *
     * @var array
     */
    public array $ERROR_CODE;

    /**
     * CONSTRUCTOR
     * Load the server-side error code catalog.
     */
    final private function __construct()
    {
        $errorCode = require ERROR_CODE_PATH;
        $this->ERROR_CODE = is_array($errorCode) ? $errorCode : [];
    }

    /**
     * GET INSTANCE
     *
     * @return ErrorCode
     */
    final public static function getInstance(): ErrorCode
    {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get error text.
     * @param string $errorCode Error code.
     *
     * @return string ERROR TEXT
     */
    final public function getErrorText(string $errorCode): string
    {
        if (isset($this->ERROR_CODE[$errorCode]['message'])) {
            return (string)$this->ERROR_CODE[$errorCode]['message'];
        } else {
            return 'Error code [' . $errorCode . '] is not defined.';
        }
    }

    /**
     * Get HTTP status for error code.
     *
     * @param string $errorCode Error code.
     *
     * @return integer HTTP status code.
     */
    final public function getHttpStatus(string $errorCode): int
    {
        if (isset($this->ERROR_CODE[$errorCode]['httpStatus'])) {
            return (int)$this->ERROR_CODE[$errorCode]['httpStatus'];
        }
        return 500;
    }

    /**
     * Copy inhibit.
     *
     * @return never
     */
    final public function __clone(): never
    {
        throw new \RuntimeException('Clone is not allowed against ' . get_class($this));
    }
}
