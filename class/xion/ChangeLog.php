<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * ChangeLog — human-readable change history for any entity.
 *
 * Records what changed, who changed it, and when. Each entry has an action
 * label (e.g. 'created', 'updated', 'status_changed') and an optional
 * human-readable summary. Supports field-level before/after snapshots stored
 * as JSON for structured diffing.
 *
 * ## Usage
 *
 * ```php
 * $cl = new ChangeLog($pdo);
 *
 * // Log a change
 * $cl->record('post', '42', 'updated', 'user-1', 'Changed title',
 *             ['title' => 'Old'], ['title' => 'New']);
 *
 * // Query
 * $cl->history('post', '42');
 * $cl->recentByActor('user-1', 20);
 * $cl->count('post', '42');
 * $cl->purgeOlderThan(365);
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE change_log (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     entity_type VARCHAR(100) NOT NULL,
 *     entity_id   VARCHAR(255) NOT NULL,
 *     action      VARCHAR(100) NOT NULL,
 *     actor_id    VARCHAR(255) NOT NULL DEFAULT '',
 *     summary     TEXT         NOT NULL DEFAULT '',
 *     before_data TEXT         NOT NULL DEFAULT '',
 *     after_data  TEXT         NOT NULL DEFAULT '',
 *     changed_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class ChangeLog
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Record a change event.
     *
     * @param  string              $entityType  Entity type (e.g. 'post', 'user').
     * @param  string              $entityId    Entity identifier.
     * @param  string              $action      Action label (e.g. 'created', 'updated').
     * @param  string              $actorId     Who made the change (user ID or system).
     * @param  string              $summary     Human-readable description.
     * @param  array<string,mixed> $before      Field values before the change.
     * @param  array<string,mixed> $after       Field values after the change.
     * @return int  The new record ID.
     * @throws \InvalidArgumentException if entity_type, entity_id, or action is empty.
     */
    public function record(
        string $entityType,
        string $entityId,
        string $action,
        string $actorId = '',
        string $summary = '',
        array $before = [],
        array $after = []
    ): int {
        [$entityType, $entityId, $action] = $this->validateInputs($entityType, $entityId, $action);

        $stmt = $this->db()->prepare(
            'INSERT INTO change_log
                (entity_type, entity_id, action, actor_id, summary, before_data, after_data)
             VALUES (:type, :id, :action, :actor, :summary, :before, :after)'
        );
        $stmt->execute([
            ':type'    => $entityType,
            ':id'      => $entityId,
            ':action'  => $action,
            ':actor'   => $actorId,
            ':summary' => $summary,
            ':before'  => $before !== [] ? json_encode($before, JSON_UNESCAPED_UNICODE) : '',
            ':after'   => $after !== [] ? json_encode($after, JSON_UNESCAPED_UNICODE) : '',
        ]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Get the change history for an entity, oldest first.
     *
     * @return list<array<string,mixed>>
     */
    public function history(string $entityType, string $entityId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, entity_type, entity_id, action, actor_id, summary, before_data, after_data, changed_at
             FROM change_log
             WHERE entity_type = :type AND entity_id = :id
             ORDER BY id ASC'
        );
        $stmt->execute([':type' => trim($entityType), ':id' => trim($entityId)]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Get the most recent change entries made by a specific actor, newest first.
     *
     * @return list<array<string,mixed>>
     */
    public function recentByActor(string $actorId, int $limit = 20): array
    {
        $limit = max(1, $limit);
        $stmt  = $this->db()->prepare(
            "SELECT id, entity_type, entity_id, action, summary, changed_at
             FROM change_log
             WHERE actor_id = :actor
             ORDER BY id DESC
             LIMIT {$limit}"
        );
        $stmt->execute([':actor' => $actorId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Get the most recent change for an entity.
     *
     * @return array<string,mixed>|null
     */
    public function latest(string $entityType, string $entityId): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, entity_type, entity_id, action, actor_id, summary, changed_at
             FROM change_log
             WHERE entity_type = :type AND entity_id = :id
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':type' => trim($entityType), ':id' => trim($entityId)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Count change entries for an entity.
     */
    public function count(string $entityType, string $entityId): int
    {
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM change_log WHERE entity_type = :type AND entity_id = :id'
        );
        $stmt->execute([':type' => trim($entityType), ':id' => trim($entityId)]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Purge change log entries older than N days.
     *
     * @return int Number of rows deleted.
     */
    public function purgeOlderThan(int $days): int
    {
        $cutoff = (new \DateTimeImmutable())->modify("-{$days} days")->format('Y-m-d H:i:s');
        $stmt   = $this->db()->prepare(
            'DELETE FROM change_log WHERE changed_at < :cutoff'
        );
        $stmt->execute([':cutoff' => $cutoff]);
        return $stmt->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    /**
     * @return array{string, string, string}
     */
    private function validateInputs(string $entityType, string $entityId, string $action): array
    {
        $entityType = trim($entityType);
        $entityId   = trim($entityId);
        $action     = trim($action);
        if ($entityType === '') {
            throw new \InvalidArgumentException('entity_type must not be empty.');
        }
        if ($entityId === '') {
            throw new \InvalidArgumentException('entity_id must not be empty.');
        }
        if ($action === '') {
            throw new \InvalidArgumentException('action must not be empty.');
        }
        return [$entityType, $entityId, $action];
    }
}
