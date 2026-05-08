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
 * @license   https://choosealicense.com/no-permission/ NO LICENSE
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
     * @var ErrorCode
     */
    private static $instance;

    /**
     * Error code array.
     *
     * @var array
     */
    public $ERROR_CODE;

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
        if (array_key_exists($errorCode, $this->ERROR_CODE)) {
            return $this->ERROR_CODE[$errorCode];
        } else {
            return 'Error code [' . $errorCode . '] is not defined.';
        }
    }

    /**
     * Copy inhibit.
     *
     * @return void
     */
    final public function __clone()
    {
        throw new \RuntimeException('Clone is not allowed against ' . get_class($this));
    }
}
