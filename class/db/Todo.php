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

namespace Nene\Database;

use Nene\Xion\DataModelBase as DataModelBase;

/**
 * TODO item model.
 */
class Todo extends DataModelBase
{
    /**
     * Table schema
     *
     * @var array
     */
    protected static $schema = [
        'id'           => parent::INTEGER,
        'created_at'   => parent::DATETIME,
        'updated_at'   => parent::DATETIME,
        'user_id'      => parent::INTEGER,
        'title'        => parent::STRING,
        'is_completed' => parent::BOOLEAN,
        'is_deleted'   => parent::BOOLEAN
    ];

    /**
     * Validation conditions
     *
     * @var array
     */
    protected static $validation = [
        'user_id'      => ['required' => true],
        'title'        => ['required' => true, 'maxlength' => 255],
        'is_completed' => ['required' => true, 'bool' => true],
        'is_deleted'   => ['required' => true, 'bool' => true]
    ];
}
