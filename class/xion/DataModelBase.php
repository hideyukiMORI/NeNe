<?php

declare(strict_types=1);

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
    protected $data    = [];
    protected static $schema  = [];
    protected $LOGGER;
    protected $CLASS;
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

    public function __get($prop)
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

    public function __isset($prop): bool
    {
        return isset($this->data[$prop]);
    }

    public function set($prop, $val): bool
    {
        if (!$this->validate($prop, $val)) {
            return false;
        }
        $this->__set($prop, $val);
        return true;
    }

    public function setParam($prop, $val)
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
     * @param string $prop  Object parameter name.
     * @param string $postProp  POST parameter name.
     *
     * @return DataModelBase
     */
    public function setParamPostString($prop, $postProp = '')
    {
        $postProp = $postProp == '' ? $prop : $postProp;
        return $this->setParam($prop, (string)filter_input(INPUT_POST, $postProp));
    }

    /**
     * Set filtered POST.
     *
     * Set the POST data obtained from filter_input to the specified parameter.
     *
     * @param string $prop  Object parameter name.
     * @param string $postProp  POST parameter name.
     *
     * @return DataModelBase
     */
    public function setParamPostInt($prop, $postProp = '')
    {
        $postProp = $postProp == '' ? $prop : $postProp;
        return $this->setParam($prop, (int)filter_input(INPUT_POST, $postProp));
    }

    public function __set($prop, $val)
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
            return;
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

    public function toArray(): array
    {
        return $this->data;
    }

    public function fromArray(array $arr)
    {
        foreach ($arr as $key => $val) {
            $this->__set($key, $val);
        }
    }

    abstract public function isValid();

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
    abstract public function validate(string $prop = '', string $value = '');

    /**
     * Do valid
     *
     * Validate parameters.
     *
     * @param mixed $param The value to validate.
     * @param array $validateArray An array of validation conditions.
     * @return bool Validation results.
     */
    protected function doValid($param, $validateArray)
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
                    if (mb_strlen($param) > $value) {
                        $flag = false;
                        break 2;
                    }
                    break;
                case 'minlength':
                    if (mb_strlen($param) < $value) {
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
     */
    public function setNow()
    {
        if (strlen($this->created_at) <= 1) {
            $this->created_at = date('YmdHi');
        }
        $this->updated_at = date('YmdHi');
        return;
    }

    public function getSchema()
    {
        return static::$schema;
    }
}
