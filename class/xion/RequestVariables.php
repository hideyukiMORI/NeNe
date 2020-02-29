<?php
namespace Nene\Xion;

/**
 * AYANE : ayane.co.jp
 * powerd by NENE.
 *
 * @author hideyuki MORI
 */

/**
 * Abstract class of request variable class.
 * Implements getter and has.
 *
 * @author      HideyukiMORI
 */
abstract class RequestVariables
{
    protected $_values;

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
            $ret = $this->_values;
        } elseif ($this->has($key) == true) {
            $ret = $this->_values[$key];
        }
        return $ret;
    }



    /**
     * Check for the existence of a value.
     *
     * @param string $key   Parameter name.
     * @return bool
     */
    public function has(string $key) : bool
    {
        if (array_key_exists($key, $this->_values) == false) {
            return false;
        }
        return true;
    }
}
