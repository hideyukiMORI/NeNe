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

namespace Nene\Database;

use Nene\Xion\DataModelBase as DataModelBase;

/**
 * User account model.
 */
class User extends DataModelBase
{
    /**
     * Table schema
     *
     * @var array
     */
    protected static $schema = [
        'id'         => parent::INTEGER,
        'created_at' => parent::DATETIME,
        'updated_at' => parent::DATETIME,
        'user_id'    => parent::STRING,
        'user_pass'  => parent::STRING,
        'user_name'  => parent::STRING,
        'e_mail'     => parent::STRING,
        'is_deleted' => parent::STRING
    ];

    /**
     * Validation conditions
     *
     * @var array
     */
    protected static $validation = [
        'user_id'    => ['required' => true],
        'created_at' => ['required' => true],
        'updated_at' => ['required' => true],
        'user_id'    => ['required' => true, 'maxlength' => 64],
        'user_pass'  => ['required' => true, 'maxlength' => 64, 'minlength' => 6],
        'user_name'  => ['required' => true, 'maxlength' => 255],
        'e_mail'     => ['required' => true, 'maxlength' => 255],
        'is_deleted' => ['required' => true, 'bool' => true]
    ];
}
