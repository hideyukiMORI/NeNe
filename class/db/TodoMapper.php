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
 * @license   https://choosealicense.com/no-permission/ NO LICENSE
 * @link      https://ayane.co.jp/
 */

declare(strict_types=1);

namespace Nene\Database;

use Nene\Xion\DataMapperBase as DataMapperBase;
use PDO;
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

    /**
     * Find TODO rows owned by a user as arrays.
     *
     * @param int $userId User primary key.
     *
     * @return array<int,array<string,mixed>> TODO rows.
     */
    final public function findRowsByUserId(int $userId): array
    {
        $stmt = $this->DB->prepare('
            SELECT id, user_id, title, is_completed, created_at, updated_at
            FROM ' . static::TARGET_TABLE . '
            WHERE user_id = :user_id
            AND is_deleted = 0
            ORDER BY id ASC
        ');
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        return $this->execute($stmt)->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Find one TODO row owned by a user.
     *
     * @param int $userId User primary key.
     * @param int $id     TODO primary key.
     *
     * @return array<string,mixed>|null TODO row.
     */
    final public function findRowByUserIdAndId(int $userId, int $id): ?array
    {
        $stmt = $this->DB->prepare('
            SELECT id, user_id, title, is_completed, created_at, updated_at
            FROM ' . static::TARGET_TABLE . '
            WHERE id = :id
            AND user_id = :user_id
            AND is_deleted = 0
            LIMIT 1
        ');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $row = $this->execute($stmt)->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * Create a TODO row.
     *
     * @param int    $userId User primary key.
     * @param string $title  TODO title.
     *
     * @return array<string,mixed> Created TODO row.
     */
    final public function createForUser(int $userId, string $title): array
    {
        $stmt = $this->DB->prepare('
            INSERT INTO ' . static::TARGET_TABLE . ' (
                user_id,
                title,
                is_completed,
                is_deleted
            ) VALUES (
                :user_id,
                :title,
                0,
                0
            )
        ');
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':title', $title, PDO::PARAM_STR);
        $this->execute($stmt);
        $row = $this->findRowByUserIdAndId($userId, (int)$this->DB->lastInsertId());
        if ($row === null) {
            throw new \RuntimeException('Created TODO row could not be loaded.');
        }
        return $row;
    }

    /**
     * Update a TODO row.
     *
     * @param int         $userId      User primary key.
     * @param int         $id          TODO primary key.
     * @param string|null $title       TODO title.
     * @param bool|null   $isCompleted Completed flag.
     *
     * @return array<string,mixed>|null Updated TODO row.
     */
    final public function updateForUser(int $userId, int $id, ?string $title, ?bool $isCompleted): ?array
    {
        $sets = [];
        if ($title !== null) {
            $sets[] = 'title = :title';
        }
        if ($isCompleted !== null) {
            $sets[] = 'is_completed = :is_completed';
        }
        if ($sets === []) {
            return $this->findRowByUserIdAndId($userId, $id);
        }
        $stmt = $this->DB->prepare(sprintf(
            'UPDATE %s SET %s WHERE id = :id AND user_id = :user_id AND is_deleted = 0',
            static::TARGET_TABLE,
            implode(', ', $sets)
        ));
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        if ($title !== null) {
            $stmt->bindValue(':title', $title, PDO::PARAM_STR);
        }
        if ($isCompleted !== null) {
            $stmt->bindValue(':is_completed', $isCompleted ? 1 : 0, PDO::PARAM_INT);
        }
        $this->execute($stmt);
        return $this->findRowByUserIdAndId($userId, $id);
    }

    /**
     * Mark a TODO row as deleted.
     *
     * @param int $userId User primary key.
     * @param int $id     TODO primary key.
     *
     * @return bool True when a row was deleted.
     */
    final public function deleteForUser(int $userId, int $id): bool
    {
        $stmt = $this->DB->prepare('
            UPDATE ' . static::TARGET_TABLE . '
            SET is_deleted = 1
            WHERE id = :id
            AND user_id = :user_id
            AND is_deleted = 0
        ');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $this->execute($stmt);
        return $stmt->rowCount() > 0;
    }
}
