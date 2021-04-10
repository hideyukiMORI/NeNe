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
 * Abstract class of request variable class.
 * Implements getter and has.
 *
 * @author      HideyukiMORI
 */
abstract class RequestVariables
{
    protected $values;

    /**
     * CONSTRUCTOR.
     */
    public function __construct()
    {
        $this->setValues();
    }

    /**
     * Set values
     *
     * Initialize the value
     */
    abstract protected function setValues();

    /**
     * Get value.
     *
     * @param string $key   Parameter name.
     * @return mixed value [string or array]
     */
    public function get(string $key = null)
    {
        $ret = null;
        if ($key == null) {
            $ret = $this->values;
        } elseif ($this->has($key) == true) {
            $ret = $this->values[$key];
        }
        return $ret;
    }

    /**
     * Check for the existence of a value.
     *
     * @param string $key   Parameter name.
     * @return bool
     */
    public function has(string $key): bool
    {
        if (array_key_exists($key, $this->values) == false) {
            return false;
        }
        return true;
    }
}
