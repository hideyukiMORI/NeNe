<?php
namespace Nene\Xion;

use Nene\Xion as Xion;

/**
 * AYANE : ayane.co.jp
 * powerd by NENE.
 *
 * @author hideyuki MORI
 */

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
    const BOOLEAN       = 'boolean';
    const INTEGER       = 'integer';
    const DOUBLE        = 'double';
    const FLOAT         = 'double';
    const STRING        = 'string';
    const DATETIME      = 'dateTime';
    const DATE          = 'date';
    protected $_data    = [];
    protected static $_schema  = [];
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
        $this->CLASS = 'Database\\'.end($classPathArray);
        if (APP_CONTROLLER != 'debug' && APP_CONTROLLER != 'stub') {
            $this->LOGGER->addInfo('NEW : '.$this->CLASS);
        }
        $this->ERROR_CODE = Xion\ErrorCode::getInstance();
    }



    public function __get($prop)
    {
        if (isset($this->_data[$prop])) {
            return $this->_data[$prop];
        } elseif (isset(static::$_schema[$prop])) {
            return null;
        } else {
            echo 'DATA MODEL ERROR. Unable to get parameters. The property "'.$prop.'" may not be defined.';
            throw new \InvalidArgumentException('GET ' . $prop . ' IS DISABLE.');
            exit();
        }
    }



    public function __isset($prop) : bool
    {
        return isset($this->_data[$prop]);
    }



    public function set($prop, $val) : bool
    {
        if (!$this->validate($prop, $val)) {
            return false;
        }
        $this->__set($prop, $val);
        return true;
    }



    public function __set($prop, $val)
    {
        if (!isset(static::$_schema[$prop])) {
            echo 'DATA MODEL ERROR. Unable to set parameters. The property "'.$prop.'" may not be defined.';
            throw new \InvalidArgumentException('SET ' . $prop . ' IS DISABLE.');
            exit();
        }

        $schema = static::$_schema[$prop];
        $type = gettype($val);
        if ($type === $schema) {
            $this->_data[$prop] = $val;
            return;
        }

        switch ($schema) {
            case self::BOOLEAN:
                return $this->_data[$prop] = (bool) $val;
            case self::INTEGER:
                return $this->_data[$prop] = (int) $val;
            case self::DOUBLE:
                return $this->_data[$prop] = (double) $val;
            case self::STRING:
            default:
                return $this->_data[$prop] = (string) $val;
        }
    }



    public function toArray() : array
    {
        return $this->_data;
    }



    public function fromArray(array $arr)
    {
        foreach ($arr as $key => $val) {
            $this->__set($key, $val);
        }
    }



    abstract public function isValid();



    /**
     * Do valid
     *
     * Validate parameters.
     *
     * @param mixed $param The value to validate.
     * @param array $valudateArray An array of validation conditions.
     * @return bool Validation results.
     */
    protected function doValid($param, $validateArray)
    {
        $flag = true;
        foreach ($validateArray as $key => $value) {
            switch ($key) {
                case 'required':
                    if (empty($param)) {
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
     * Set filltered POST.
     *
     * Set the POST data obtained from fillter_input to the specified parameter.
     *
     * @param string $prop  Object parameter name.
     * @param string $postProp  POST parameter name.
     * @return void
     */
    public function setFillteredPost($prop, $postProp = '')
    {
        $postProp = $postProp == '' ? $prop : $postProp;
        $this->set($prop, (string)filter_input(INPUT_POST, $postProp));
    }



    /**
     * Set now.
     *
     * Use this method when the creation date and update date of the database row are string type for some reason.
     * If the createion stamp has not been set, set the creation stamp in the format of "HmdHi".
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
        return static::$_schema;
    }
}
