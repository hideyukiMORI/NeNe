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

namespace Nene\Xion;

/**
 * Share error code with javascript.
 */
class ErrorCode
{
    private static $instance;   // INSTANCE VARIABLE
    public $ERROR_CODE;         // ERROR CODE ARRAY

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
     */
    final public static function getInstance()
    {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get error text.
     * @param  string   $errorCode  ERROR CODE
     *
     * @return string   ERROR TEXT
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
     */
    final public function __clone()
    {
        throw new \RuntimeException('Clone is not allowed against ' . get_class($this));
    }
}
