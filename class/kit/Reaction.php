<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * Reaction — emoji / symbol reactions on any entity.
 *
 * Tracks per-user reactions (like, love, thumbs-up, etc.) on arbitrary
 * resources. Each user may have at most one reaction of a given type on
 * a given entity; toggling the same reaction removes it.
 *
 * ## Usage
 *
 * ```php
 * $r = new Reaction($pdo);
 *
 * // Toggle reactions
 * $r->toggle('post', '42', 'user-1', '👍');  // adds
 * $r->toggle('post', '42', 'user-1', '👍');  // removes (returns false)
 *
 * // Query
 * $counts = $r->counts('post', '42');          // ['👍' => 3, '❤️' => 1]
 * $mine   = $r->byUser('post', '42', 'user-1');// ['👍']
 * $all    = $r->forEntity('post', '42');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE reactions (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     entity_type VARCHAR(100) NOT NULL,
 *     entity_id   VARCHAR(255) NOT NULL,
 *     user_id     VARCHAR(255) NOT NULL,
 *     reaction    VARCHAR(64)  NOT NULL,
 *     created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (entity_type, entity_id, user_id, reaction)
 * );
 * ```
 */
final class Reaction
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Toggle a reaction for a user on an entity.
     *
     * If the reaction does not exist it is added and true is returned.
     * If it already exists it is removed and false is returned.
     *
     * @throws \InvalidArgumentException on empty fields.
     */
    public function toggle(
        string $entityType,
        string $entityId,
        string $userId,
        string $reaction
    ): bool {
        [$entityType, $entityId, $userId, $reaction] = $this->validate($entityType, $entityId, $userId, $reaction);

        if ($this->has($entityType, $entityId, $userId, $reaction)) {
            $this->db()->prepare(
                'DELETE FROM reactions
                 WHERE entity_type = :type AND entity_id = :eid
                   AND user_id = :uid AND reaction = :r'
            )->execute([':type' => $entityType, ':eid' => $entityId, ':uid' => $userId, ':r' => $reaction]);
            return false;
        }

        $this->db()->prepare(
            'INSERT INTO reactions (entity_type, entity_id, user_id, reaction)
             VALUES (:type, :eid, :uid, :r)'
        )->execute([':type' => $entityType, ':eid' => $entityId, ':uid' => $userId, ':r' => $reaction]);
        return true;
    }

    /**
     * Add a reaction without toggling (idempotent — no error if already present).
     */
    public function add(string $entityType, string $entityId, string $userId, string $reaction): void
    {
        [$entityType, $entityId, $userId, $reaction] = $this->validate($entityType, $entityId, $userId, $reaction);

        $driver = $this->db()->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            $sql = 'INSERT IGNORE INTO reactions (entity_type, entity_id, user_id, reaction)
                    VALUES (:type, :eid, :uid, :r)';
        } else {
            $sql = 'INSERT OR IGNORE INTO reactions (entity_type, entity_id, user_id, reaction)
                    VALUES (:type, :eid, :uid, :r)';
        }
        $this->db()->prepare($sql)->execute([
            ':type' => $entityType,
            ':eid'  => $entityId,
            ':uid'  => $userId,
            ':r'    => $reaction,
        ]);
    }

    /**
     * Remove a specific reaction from a user on an entity.
     *
     * @return bool True if a row was deleted.
     */
    public function remove(string $entityType, string $entityId, string $userId, string $reaction): bool
    {
        [$entityType, $entityId, $userId, $reaction] = $this->validate($entityType, $entityId, $userId, $reaction);

        $stmt = $this->db()->prepare(
            'DELETE FROM reactions
             WHERE entity_type = :type AND entity_id = :eid
               AND user_id = :uid AND reaction = :r'
        );
        $stmt->execute([':type' => $entityType, ':eid' => $entityId, ':uid' => $userId, ':r' => $reaction]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Check whether a user has a specific reaction on an entity.
     */
    public function has(string $entityType, string $entityId, string $userId, string $reaction): bool
    {
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM reactions
             WHERE entity_type = :type AND entity_id = :eid
               AND user_id = :uid AND reaction = :r'
        );
        $stmt->execute([
            ':type' => $entityType,
            ':eid'  => $entityId,
            ':uid'  => $userId,
            ':r'    => $reaction,
        ]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Get reaction counts grouped by reaction type for an entity.
     *
     * @return array<string, int>  e.g. ['👍' => 3, '❤️' => 1]
     */
    public function counts(string $entityType, string $entityId): array
    {
        [$entityType, $entityId] = $this->validateEntity($entityType, $entityId);
        $stmt = $this->db()->prepare(
            'SELECT reaction, COUNT(*) AS cnt
             FROM reactions
             WHERE entity_type = :type AND entity_id = :eid
             GROUP BY reaction
             ORDER BY cnt DESC, reaction ASC'
        );
        $stmt->execute([':type' => $entityType, ':eid' => $entityId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            return [];
        }
        $result = [];
        foreach ($rows as $row) {
            $result[(string)$row['reaction']] = (int)$row['cnt'];
        }
        return $result;
    }

    /**
     * Get the list of reaction types a specific user has on an entity.
     *
     * @return list<string>
     */
    public function byUser(string $entityType, string $entityId, string $userId): array
    {
        [$entityType, $entityId] = $this->validateEntity($entityType, $entityId);
        $userId = trim($userId);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }
        $stmt = $this->db()->prepare(
            'SELECT reaction FROM reactions
             WHERE entity_type = :type AND entity_id = :eid AND user_id = :uid
             ORDER BY reaction ASC'
        );
        $stmt->execute([':type' => $entityType, ':eid' => $entityId, ':uid' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    /**
     * Get all reaction rows for an entity.
     *
     * @return list<array<string,mixed>>
     */
    public function forEntity(string $entityType, string $entityId): array
    {
        [$entityType, $entityId] = $this->validateEntity($entityType, $entityId);
        $stmt = $this->db()->prepare(
            'SELECT id, entity_type, entity_id, user_id, reaction, created_at
             FROM reactions
             WHERE entity_type = :type AND entity_id = :eid
             ORDER BY created_at ASC, id ASC'
        );
        $stmt->execute([':type' => $entityType, ':eid' => $entityId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Remove all reactions from all users on an entity.
     *
     * @return int Number of rows deleted.
     */
    public function clearEntity(string $entityType, string $entityId): int
    {
        [$entityType, $entityId] = $this->validateEntity($entityType, $entityId);
        $stmt = $this->db()->prepare(
            'DELETE FROM reactions WHERE entity_type = :type AND entity_id = :eid'
        );
        $stmt->execute([':type' => $entityType, ':eid' => $entityId]);
        return $stmt->rowCount();
    }

    /**
     * Remove all reactions by a user across all entities.
     *
     * @return int Number of rows deleted.
     */
    public function clearUser(string $userId): int
    {
        $userId = trim($userId);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }
        $stmt = $this->db()->prepare('DELETE FROM reactions WHERE user_id = :uid');
        $stmt->execute([':uid' => $userId]);
        return $stmt->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    /**
     * @return array{string, string}
     */
    private function validateEntity(string $entityType, string $entityId): array
    {
        $entityType = trim($entityType);
        $entityId   = trim($entityId);
        if ($entityType === '') {
            throw new \InvalidArgumentException('entity_type must not be empty.');
        }
        if ($entityId === '') {
            throw new \InvalidArgumentException('entity_id must not be empty.');
        }
        return [$entityType, $entityId];
    }

    /**
     * @return array{string, string, string, string}
     */
    private function validate(string $entityType, string $entityId, string $userId, string $reaction): array
    {
        [$entityType, $entityId] = $this->validateEntity($entityType, $entityId);
        $userId   = trim($userId);
        $reaction = trim($reaction);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }
        if ($reaction === '') {
            throw new \InvalidArgumentException('reaction must not be empty.');
        }
        return [$entityType, $entityId, $userId, $reaction];
    }
}
