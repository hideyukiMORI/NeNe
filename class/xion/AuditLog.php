<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * AuditLog — append-only record of significant actions for compliance and forensics.
 *
 * Records who did what to which resource, from where, and when.
 * All entries are immutable once written. Supports structured context payloads
 * stored as JSON for flexible metadata.
 *
 * ## Usage
 *
 * ```php
 * $al = new AuditLog($pdo);
 *
 * // Record an action
 * $al->record('user.login', 'user-1', 'user', 'user-1', ['ip' => '1.2.3.4']);
 * $al->record('post.delete', 'admin-1', 'post', '42');
 *
 * // Query recent activity
 * $al->recent(20);
 *
 * // Filter by actor
 * $al->forActor('admin-1');
 *
 * // Filter by resource
 * $al->forResource('post', '42');
 *
 * // Purge old records
 * $al->purgeOlderThan(90);
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE audit_log (
 *     id            INTEGER PRIMARY KEY AUTOINCREMENT,
 *     action        VARCHAR(100) NOT NULL,
 *     actor_id      VARCHAR(255) NOT NULL DEFAULT '',
 *     resource_type VARCHAR(100) NOT NULL DEFAULT '',
 *     resource_id   VARCHAR(255) NOT NULL DEFAULT '',
 *     context       TEXT         NOT NULL DEFAULT '{}',
 *     created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class AuditLog
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Record an audit event.
     *
     * @param  string              $action       Dot-namespaced action string (e.g. 'user.login').
     * @param  string              $actorId      Who performed the action.
     * @param  string              $resourceType Type of the affected resource (e.g. 'post').
     * @param  string              $resourceId   ID of the affected resource.
     * @param  array<string,mixed> $context      Optional key-value metadata (stored as JSON).
     * @return int  The new log entry ID.
     * @throws \InvalidArgumentException if action is empty.
     */
    public function record(
        string $action,
        string $actorId = '',
        string $resourceType = '',
        string $resourceId = '',
        array $context = []
    ): int {
        $action = trim($action);
        if ($action === '') {
            throw new \InvalidArgumentException('action must not be empty.');
        }

        $contextJson = empty($context) ? '{}' : json_encode($context, JSON_UNESCAPED_UNICODE);

        $this->db()->prepare(
            'INSERT INTO audit_log (action, actor_id, resource_type, resource_id, context)
             VALUES (:action, :actor, :rtype, :rid, :ctx)'
        )->execute([
            ':action' => $action,
            ':actor'  => $actorId,
            ':rtype'  => $resourceType,
            ':rid'    => $resourceId,
            ':ctx'    => $contextJson,
        ]);

        return (int)$this->db()->lastInsertId();
    }

    /**
     * Get the most recent log entries.
     *
     * @return list<array<string,mixed>>
     */
    public function recent(int $limit = 50): array
    {
        $limit = max(1, $limit);
        $stmt  = $this->db()->prepare(
            "SELECT id, action, actor_id, resource_type, resource_id, context, created_at
             FROM audit_log
             ORDER BY id DESC
             LIMIT {$limit}"
        );
        $stmt->execute();
        return $this->decode($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Get log entries for a specific actor.
     *
     * @return list<array<string,mixed>>
     */
    public function forActor(string $actorId, int $limit = 50): array
    {
        $limit = max(1, $limit);
        $stmt  = $this->db()->prepare(
            "SELECT id, action, actor_id, resource_type, resource_id, context, created_at
             FROM audit_log
             WHERE actor_id = :actor
             ORDER BY id DESC
             LIMIT {$limit}"
        );
        $stmt->execute([':actor' => $actorId]);
        return $this->decode($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Get log entries for a specific resource.
     *
     * @return list<array<string,mixed>>
     */
    public function forResource(string $resourceType, string $resourceId, int $limit = 50): array
    {
        $limit = max(1, $limit);
        $stmt  = $this->db()->prepare(
            "SELECT id, action, actor_id, resource_type, resource_id, context, created_at
             FROM audit_log
             WHERE resource_type = :rtype AND resource_id = :rid
             ORDER BY id DESC
             LIMIT {$limit}"
        );
        $stmt->execute([':rtype' => $resourceType, ':rid' => $resourceId]);
        return $this->decode($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Get log entries matching a specific action.
     *
     * @return list<array<string,mixed>>
     */
    public function forAction(string $action, int $limit = 50): array
    {
        $limit = max(1, $limit);
        $stmt  = $this->db()->prepare(
            "SELECT id, action, actor_id, resource_type, resource_id, context, created_at
             FROM audit_log
             WHERE action = :action
             ORDER BY id DESC
             LIMIT {$limit}"
        );
        $stmt->execute([':action' => $action]);
        return $this->decode($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Count log entries (optionally filtered by actor or action).
     */
    public function count(?string $actorId = null, ?string $action = null): int
    {
        if ($actorId !== null && $action !== null) {
            $stmt = $this->db()->prepare(
                'SELECT COUNT(*) FROM audit_log WHERE actor_id = :actor AND action = :action'
            );
            $stmt->execute([':actor' => $actorId, ':action' => $action]);
        } elseif ($actorId !== null) {
            $stmt = $this->db()->prepare(
                'SELECT COUNT(*) FROM audit_log WHERE actor_id = :actor'
            );
            $stmt->execute([':actor' => $actorId]);
        } elseif ($action !== null) {
            $stmt = $this->db()->prepare(
                'SELECT COUNT(*) FROM audit_log WHERE action = :action'
            );
            $stmt->execute([':action' => $action]);
        } else {
            $stmt = $this->db()->prepare('SELECT COUNT(*) FROM audit_log');
            $stmt->execute();
        }
        return (int)$stmt->fetchColumn();
    }

    /**
     * Purge entries older than N days.
     *
     * @return int Number of rows deleted.
     */
    public function purgeOlderThan(int $days): int
    {
        $cutoff = (new \DateTimeImmutable())->modify("-{$days} days")->format('Y-m-d H:i:s');
        $stmt   = $this->db()->prepare('DELETE FROM audit_log WHERE created_at < :cutoff');
        $stmt->execute([':cutoff' => $cutoff]);
        return $stmt->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    /**
     * Decode the JSON context field in each row.
     *
     * @param  list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function decode(array $rows): array
    {
        foreach ($rows as &$row) {
            $decoded = json_decode((string)($row['context'] ?? '{}'), true);
            $row['context'] = is_array($decoded) ? $decoded : [];
        }
        return $rows;
    }
}
