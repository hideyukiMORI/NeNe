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
 * Abstract class of request variable class.
 * Implements getter and has.
 *
 * @author      HideyukiMORI
 */
abstract class RequestVariables
{
    /**
     * Request variables
     *
     * @var array<string,mixed>
     */
    protected $values = [];

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
     *
     * @return void
     */
    abstract protected function setValues(): void;

    /**
     * Get value.
     *
     * @param string|null $key Parameter name.
     *
     * @return mixed value [string or array]
     */
    public function get(?string $key = null)
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
     * @param string $key Parameter name.
     *
     * @return boolean
     */
    public function has(string $key): bool
    {
        if (array_key_exists($key, $this->values) == false) {
            return false;
        }
        return true;
    }
}
