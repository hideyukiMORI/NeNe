<?php

namespace Nene\Database;

use Nene\Xion\DataModelBase as DataModelBase;

/**
 * AYANE : ayane.co.jp
 * powered by NENE.
 *
 * @author hideyuki MORI
 */

/**
 * User account model.
 */
class User extends DataModelBase
{



    protected static $schema = [
        'id'            => parent::INTEGER,
        'created_at'    => parent::DATETIME,
        'updated_at'    => parent::DATETIME,
        'user_id'       => parent::STRING,
        'user_pass'     => parent::STRING,
        'user_name'     => parent::STRING,
        'e_mail'        => parent::STRING,
        'is_deleted'    => parent::STRING
    ];



    /**
     * Validate
     * If the property is not specified, validate all schemas.
     * If the property is specified, validate the value passed in the argument and return the result.
     *
     * @param string $prop  Validation target. If not specified, all schemas.
     * @param string $value  The value you want to validate.
     */
    public function validate($prop = '', $value = '')
    {
        $validateArray = [
            'user_id'           => ['required' => true],
            'created_at'        => ['required' => true],
            'updated_at'        => ['required' => true],
            'user_id'           => ['required' => true, 'maxlength' => 64],
            'user_pass'         => ['required' => true, 'maxlength' => 64, 'minlength' => 6],
            'user_name'         => ['required' => true, 'maxlength' => 255],
            'e_mail'            => ['required' => true, 'maxlength' => 255],
            'is_deleted'        => ['required' => true, 'bool' => true]
        ];
        if ($prop == '') {
            foreach ($validateArray as $key => $val) {
                if (!$this->doValid($this->$key, $val)) {
                    return ($key);
                }
            }
        } elseif (in_array($prop, $validateArray, true)) {
            if (!$this->doValid($value, $validateArray[$prop])) {
                return (false);
            }
        }
        return true;
    }



    /**
     * Is valid.
     * Returns the result of object validation as a boolean.
     *
     * @return bool
     */
    public function isValid()
    {
        return ($this->validate() === true ?: false);
    }
}
