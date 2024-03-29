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

use Nene\Xion as Xion;

/**
 * Abstract class for data model
 * Superclass of all data models.
 * This class has common data model methods.
 *
 * @author      HideyukiMORI
 * ----
 * This code is based on Hiraku NAKANO's code.
 * Thank you.
 * http://blog.tojiru.net
 *
 */
abstract class DataModelBase
{
    protected const BOOLEAN       = 'boolean';
    protected const INTEGER       = 'integer';
    protected const DOUBLE        = 'double';
    protected const FLOAT         = 'double';
    protected const STRING        = 'string';
    protected const DATETIME      = 'dateTime';
    protected const DATE          = 'date';

    /**
     * Row data.
     *
     * @var array
     */
    protected $data    = [];

    /**
     * Table schema
     *
     * @var array
     */
    protected static $schema  = [];

    /**
     * Logger
     *
     * @var Log
     */
    protected $LOGGER;

    /**
     * Class name
     *
     * @var string
     */
    protected $CLASS;

    /**
     * Error code
     *
     * @var ErrorCode
     */
    protected $ERROR_CODE;

    /**
     * CONSTRUCTOR.
     */
    public function __construct()
    {
        $this->LOGGER = Log::getInstance();
        $classPathArray = explode('\\', get_class($this));
        $this->CLASS = 'Database\\' . end($classPathArray);
        if (APP_CONTROLLER != 'debug' && APP_CONTROLLER != 'stub') {
            $this->LOGGER->addDebug('NEW : ' . $this->CLASS);
        }
        $this->ERROR_CODE = Xion\ErrorCode::getInstance();
    }

    /**
     * getter
     *
     * @param string $prop Parameter key.
     *
     * @return string|null
     */
    public function __get(string $prop)
    {
        if (isset($this->data[$prop])) {
            return $this->data[$prop];
        } elseif (isset(static::$schema[$prop])) {
            return null;
        } else {
            echo 'DATA MODEL ERROR. Unable to get parameters. The property "' . $prop . '" may not be defined.';
            throw new \InvalidArgumentException('GET ' . $prop . ' IS DISABLE.');
            exit();
        }
    }

    /**
     * Is set param.
     *
     * @param string $prop Parameter key.
     *
     * @return boolean
     */
    public function __isset(string $prop): bool
    {
        return isset($this->data[$prop]);
    }

    /**
     * setter
     *
     * @param string $prop Parameter key.
     * @param mixed  $val  Parameter value.
     *
     * @return boolean
     */
    public function set(string $prop, mixed $val): bool
    {
        if (!$this->validate($prop, $val)) {
            return false;
        }
        $this->__set($prop, $val);
        return true;
    }

    /**
     * Set the value for the parameter.
     * It corresponds to the method chain.
     *
     * @param string $prop Parameter key.
     * @param mixed  $val  Parameter value.
     *
     * @return DataModelBale
     */
    public function setParam(string $prop, mixed $val)
    {
        if (!$this->validate($prop, $val)) {
            throw new \Exception('Parameter ' . $prop . ' is a validation error. (value : ' . $val . ')');
        }
        $this->__set($prop, $val);
        return $this;
    }

    /**
     * Set filtered POST.
     *
     * Set the POST data obtained from filter_input to the specified parameter.
     *
     * @param string $prop     Object parameter name.
     * @param string $postProp POST parameter name.
     *
     * @return DataModelBase
     */
    public function setParamPostString(string $prop, string $postProp = '')
    {
        $postProp = $postProp == '' ? $prop : $postProp;
        return $this->setParam($prop, (string)filter_input(INPUT_POST, $postProp));
    }

    /**
     * Set filtered POST.
     *
     * Set the POST data obtained from filter_input to the specified parameter.
     *
     * @param string $prop     Object parameter name.
     * @param string $postProp POST parameter name.
     *
     * @return DataModelBase
     */
    public function setParamPostInt(string $prop, string $postProp = '')
    {
        $postProp = $postProp == '' ? $prop : $postProp;
        return $this->setParam($prop, (int)filter_input(INPUT_POST, $postProp));
    }

    /**
     * Setter.
     * The value is set in the parameter according to the type set in the schema.
     *
     * @param string $prop Parameter key.
     * @param mixed  $val  Parameter value.
     *
     * @return mixed
     */
    public function __set(string $prop, mixed $val)
    {
        if (!isset(static::$schema[$prop])) {
            echo 'DATA MODEL ERROR. Unable to set parameters. The property "' . $prop . '" may not be defined.';
            throw new \InvalidArgumentException('SET ' . $prop . ' IS DISABLE.');
            exit();
        }

        $schema = static::$schema[$prop];
        $type = gettype($val);
        if ($type === $schema) {
            $this->data[$prop] = $val;
            return true;
        }

        switch ($schema) {
            case self::BOOLEAN:
                return $this->data[$prop] = (bool) $val;
            case self::INTEGER:
                return $this->data[$prop] = (int) $val;
            case self::DOUBLE:
                return $this->data[$prop] = (float) $val;
            case self::STRING:
            default:
                return $this->data[$prop] = (string) $val;
        }
    }

    /**
     * Returns data as an array type.
     *
     * @return array
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * Set the value of the parameter from the array.
     *
     * @param array $arr An associative array with the values ​​you want to set.
     *
     * @return void
     */
    public function fromArray(array $arr): void
    {
        foreach ($arr as $key => $val) {
            $this->__set($key, $val);
        }
    }

    /**
     * Is valid.
     * Returns the result of object validation as a boolean.
     *
     * @return boolean
     */
    public function isValid(): bool
    {
        return ($this->validate() === true ?: false);
    }

    /**
     * Validate
     * If the property is not specified, validate all schemas.
     * If the property is specified, validate the value passed in the argument and return the result.
     *
     * @param string $prop  Validation target. If not specified, all schemas.
     * @param string $value The value you want to validate.
     *
     * @return mixed
     */
    public function validate(string $prop = '', string $value = ''): mixed
    {
        if ($prop == '') {
            foreach ($this->validation as $key => $val) {
                if (!$this->doValid($this->$key, $val)) {
                    return ($key);
                }
            }
        } elseif (in_array($prop, $this->validation, true)) {
            if (!$this->doValid($value, $this->validation[$prop])) {
                return (false);
            }
        }
        return true;
    }

    /**
     * Do valid
     *
     * Validate parameters.
     *
     * @param mixed $param         The value to validate.
     * @param array $validateArray An array of validation conditions.
     *
     * @return boolean Validation results.
     */
    protected function doValid(mixed $param, array $validateArray): bool
    {
        $flag = true;
        foreach ($validateArray as $key => $value) {
            switch ($key) {
                case 'required':
                    if (!isset($param)) {
                        $flag = false;
                        break 2;
                    }
                    break;
                case 'maxlength':
                    if (mb_strlen($param) >= $value) {
                        $flag = false;
                        break 2;
                    }
                    break;
                case 'minlength':
                    if (mb_strlen($param) <= $value) {
                        $flag = false;
                        break 2;
                    }
                    break;
                case 'zeroOrLength':
                    if (mb_strlen($param) != $value && mb_strlen($param) != 0) {
                        $flag = false;
                        break 2;
                    }
                    break;
                case 'length':
                    if (mb_strlen($param) != $value) {
                        $flag = false;
                        break 2;
                    }
                    break;
                case 'enum':
                    if (!in_array($param, $value)) {
                        $flag = false;
                        break 2;
                    }
                    break;
                case 'bool':
                    if ($param != 1 && $param != 0) {
                        $flag = false;
                        break 2;
                    }
                    break;
                default:
                    break;
            }
        }
        return $flag;
    }

    /**
     * Set now.
     *
     * Use this method when the creation date and update date of the database row are string type for some reason.
     * If the creation stamp has not been set, set the creation stamp in the format of "HmdHi".
     * And set the update stamp in the format of "HmdHi".
     *
     * @return void
     */
    public function setNow(): void
    {
        if (strlen($this->created_at) <= 1) {
            $this->created_at = date('YmdHi');
        }
        $this->updated_at = date('YmdHi');
    }

    /**
     * Get schema.
     *
     * @return array
     */
    public function getSchema(): array
    {
        return static::$schema;
    }
}
