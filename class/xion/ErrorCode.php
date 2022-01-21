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
 * Share error code with javascript.
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
     * Convert from javascript format to json and define as array.
     */
    final private function __construct()
    {
        $error_code_file    = file_get_contents(ERROR_CODE_PATH);
        $error_json         = str_replace('var ERROR_CODE = ', '', $error_code_file);
        $error_json         = substr($error_json, 0, -1);
        $error_array        = json_decode($error_json, true);
        $this->ERROR_CODE   = $error_array;
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
    final public function __clone(): void
    {
        throw new \RuntimeException('Clone is not allowed against ' . get_class($this));
    }
}
