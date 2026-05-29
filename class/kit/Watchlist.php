<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * Watchlist — users subscribe to watch entities for updates.
 *
 * Tracks which users are watching which resources (issues, pull requests,
 * documents, threads, etc.). Useful for notification routing: "notify all
 * watchers when this entity changes."
 *
 * ## Usage
 *
 * ```php
 * $wl = new Watchlist($pdo);
 *
 * // Subscribe / unsubscribe
 * $wl->watch('issue', '42', 'user-1');
 * $wl->unwatch('issue', '42', 'user-1');
 *
 * // Toggle
 * $watching = $wl->toggle('issue', '42', 'user-2'); // true = now watching
 *
 * // Query
 * $watching = $wl->isWatching('issue', '42', 'user-1'); // false
 * $watchers = $wl->watchers('issue', '42');
 * $count    = $wl->watcherCount('issue', '42');
 * $items    = $wl->watching('user-1');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE watchlist (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     entity_type VARCHAR(100) NOT NULL,
 *     entity_id   VARCHAR(255) NOT NULL,
 *     user_id     VARCHAR(255) NOT NULL,
 *     watched_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (entity_type, entity_id, user_id)
 * );
 * ```
 */
final class Watchlist
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Subscribe a user to watch an entity (idempotent).
     *
     * @throws \InvalidArgumentException on empty fields.
     */
    public function watch(string $entityType, string $entityId, string $userId): void
    {
        [$entityType, $entityId, $userId] = $this->validateAll($entityType, $entityId, $userId);

        $driver = $this->db()->getAttribute(PDO::ATTR_DRIVER_NAME);
        $ignore = $driver === 'mysql' ? 'INSERT IGNORE' : 'INSERT OR IGNORE';

        $this->db()->prepare(
            "{$ignore} INTO watchlist (entity_type, entity_id, user_id)
             VALUES (:type, :eid, :uid)"
        )->execute([':type' => $entityType, ':eid' => $entityId, ':uid' => $userId]);
    }

    /**
     * Unsubscribe a user from watching an entity.
     *
     * @return bool True if a row was deleted.
     */
    public function unwatch(string $entityType, string $entityId, string $userId): bool
    {
        [$entityType, $entityId, $userId] = $this->validateAll($entityType, $entityId, $userId);

        $stmt = $this->db()->prepare(
            'DELETE FROM watchlist
             WHERE entity_type = :type AND entity_id = :eid AND user_id = :uid'
        );
        $stmt->execute([':type' => $entityType, ':eid' => $entityId, ':uid' => $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Toggle watch state for a user on an entity.
     *
     * @return bool True if the user is now watching, false if they stopped watching.
     */
    public function toggle(string $entityType, string $entityId, string $userId): bool
    {
        if ($this->isWatching($entityType, $entityId, $userId)) {
            $this->unwatch($entityType, $entityId, $userId);
            return false;
        }
        $this->watch($entityType, $entityId, $userId);
        return true;
    }

    /**
     * Check whether a user is watching an entity.
     */
    public function isWatching(string $entityType, string $entityId, string $userId): bool
    {
        [$entityType, $entityId, $userId] = $this->validateAll($entityType, $entityId, $userId);

        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM watchlist
             WHERE entity_type = :type AND entity_id = :eid AND user_id = :uid'
        );
        $stmt->execute([':type' => $entityType, ':eid' => $entityId, ':uid' => $userId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * List all watchers of an entity.
     *
     * @return list<array<string,mixed>>
     */
    public function watchers(string $entityType, string $entityId): array
    {
        [$entityType, $entityId] = $this->validateEntity($entityType, $entityId);
        $stmt = $this->db()->prepare(
            'SELECT id, entity_type, entity_id, user_id, watched_at
             FROM watchlist
             WHERE entity_type = :type AND entity_id = :eid
             ORDER BY watched_at ASC, id ASC'
        );
        $stmt->execute([':type' => $entityType, ':eid' => $entityId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Count watchers for an entity.
     */
    public function watcherCount(string $entityType, string $entityId): int
    {
        [$entityType, $entityId] = $this->validateEntity($entityType, $entityId);
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM watchlist
             WHERE entity_type = :type AND entity_id = :eid'
        );
        $stmt->execute([':type' => $entityType, ':eid' => $entityId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * List all entities a user is watching.
     *
     * @return list<array<string,mixed>>
     */
    public function watching(string $userId): array
    {
        $userId = trim($userId);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }
        $stmt = $this->db()->prepare(
            'SELECT id, entity_type, entity_id, user_id, watched_at
             FROM watchlist
             WHERE user_id = :uid
             ORDER BY watched_at DESC, id DESC'
        );
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * List only the user IDs watching an entity.
     *
     * @return list<string>
     */
    public function watcherIds(string $entityType, string $entityId): array
    {
        [$entityType, $entityId] = $this->validateEntity($entityType, $entityId);
        $stmt = $this->db()->prepare(
            'SELECT user_id FROM watchlist
             WHERE entity_type = :type AND entity_id = :eid
             ORDER BY watched_at ASC, id ASC'
        );
        $stmt->execute([':type' => $entityType, ':eid' => $entityId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    /**
     * Remove all watchers from an entity (e.g. when the entity is deleted).
     *
     * @return int Number of rows deleted.
     */
    public function clearEntity(string $entityType, string $entityId): int
    {
        [$entityType, $entityId] = $this->validateEntity($entityType, $entityId);
        $stmt = $this->db()->prepare(
            'DELETE FROM watchlist WHERE entity_type = :type AND entity_id = :eid'
        );
        $stmt->execute([':type' => $entityType, ':eid' => $entityId]);
        return $stmt->rowCount();
    }

    /**
     * Remove all watches for a user (e.g. on account deletion).
     *
     * @return int Number of rows deleted.
     */
    public function clearUser(string $userId): int
    {
        $userId = trim($userId);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }
        $stmt = $this->db()->prepare('DELETE FROM watchlist WHERE user_id = :uid');
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
     * @return array{string, string, string}
     */
    private function validateAll(string $entityType, string $entityId, string $userId): array
    {
        [$entityType, $entityId] = $this->validateEntity($entityType, $entityId);
        $userId = trim($userId);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }
        return [$entityType, $entityId, $userId];
    }
}
