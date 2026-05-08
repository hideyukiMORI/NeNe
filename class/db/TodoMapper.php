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

use Nene\Xion\DataMapperBase as DataMapperBase;
use PDOStatement;

/**
 * TODO item data mapper.
 */
class TodoMapper extends DataMapperBase
{
    protected const MODEL_CLASS = 'Nene\Database\Todo';
    protected const TARGET_TABLE = 'todos';
    protected const KEY_SID = 'id';

    /**
     * Find TODO items owned by a user.
     *
     * @param int $userId User primary key.
     *
     * @return PDOStatement Search results.
     */
    final public function findByUserId(int $userId): PDOStatement
    {
        $stmt = $this->DB->prepare('
            SELECT * FROM ' . static::TARGET_TABLE . '
            WHERE user_id = :user_id
            AND is_deleted = 0
            ORDER BY id ASC
        ');
        $stmt->bindValue(':user_id', $userId);
        return $this->decorate($this->execute($stmt));
    }
}
