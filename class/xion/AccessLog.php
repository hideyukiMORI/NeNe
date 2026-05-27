<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * Security-focused resource access log.
 *
 * An append-only record of who accessed which resource, when, and from what
 * IP address.  Useful for security auditing, detecting suspicious access
 * patterns, and proving regulatory compliance.
 *
 * Distinct from AuditLog (which records before/after data mutations) and
 * IntegrationLog (which records outgoing HTTP calls).  AccessLog records
 * read/view events and other access actions.
 *
 * ## Usage
 *
 * ```php
 * $al = new AccessLog($pdo);
 *
 * // Record a read event
 * $al->record('document', 'doc-42', 'user-1', 'read', '203.0.113.1');
 * $al->record('invoice', 'inv-99', 'user-1', 'download');
 *
 * // Audit queries
 * $al->forResource('document', 'doc-42');
 * $al->byActor('user-1');
 * $al->purgeOlderThan('2025-01-01 00:00:00');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE access_log (
 *     id             INTEGER PRIMARY KEY AUTOINCREMENT,
 *     resource_type  VARCHAR(100) NOT NULL,
 *     resource_id    VARCHAR(255) NOT NULL,
 *     actor_id       VARCHAR(255) NOT NULL,
 *     action         VARCHAR(100) NOT NULL,
 *     ip_address     VARCHAR(45)  NULL,
 *     created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class AccessLog
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── private helpers ───────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    // ── record ────────────────────────────────────────────────────────────────

    /**
     * Append an access event.
     *
     * @return int New log entry ID.
     * @throws \InvalidArgumentException if required fields are empty.
     */
    public function record(
        string $resourceType,
        string $resourceId,
        string $actorId,
        string $action,
        ?string $ipAddress = null,
    ): int {
        if ($resourceType === '') {
            throw new \InvalidArgumentException('resourceType must not be empty.');
        }

        if ($resourceId === '') {
            throw new \InvalidArgumentException('resourceId must not be empty.');
        }

        if ($actorId === '') {
            throw new \InvalidArgumentException('actorId must not be empty.');
        }

        if ($action === '') {
            throw new \InvalidArgumentException('action must not be empty.');
        }

        $stmt = $this->db()->prepare(
            'INSERT INTO access_log (resource_type, resource_id, actor_id, action, ip_address)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$resourceType, $resourceId, $actorId, $action, $ipAddress]);
        return (int)$this->db()->lastInsertId();
    }

    // ── queries ───────────────────────────────────────────────────────────────

    /**
     * Return recent access events for a specific resource, newest first.
     *
     * @return array<int,array<string,mixed>>
     */
    public function forResource(string $resourceType, string $resourceId, int $limit = 50): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM access_log
             WHERE resource_type = ? AND resource_id = ?
             ORDER BY id DESC LIMIT ?'
        );
        $stmt->execute([$resourceType, $resourceId, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Return recent access events by a specific actor, newest first.
     *
     * @return array<int,array<string,mixed>>
     */
    public function byActor(string $actorId, int $limit = 50): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM access_log
             WHERE actor_id = ?
             ORDER BY id DESC LIMIT ?'
        );
        $stmt->execute([$actorId, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Return recent access events for a specific action type, newest first.
     *
     * @return array<int,array<string,mixed>>
     */
    public function byAction(string $action, int $limit = 50): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM access_log
             WHERE action = ?
             ORDER BY id DESC LIMIT ?'
        );
        $stmt->execute([$action, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── purge ─────────────────────────────────────────────────────────────────

    /**
     * Delete entries older than the given datetime string.
     *
     * @return int Number of rows deleted.
     */
    public function purgeOlderThan(string $before): int
    {
        $stmt = $this->db()->prepare(
            'DELETE FROM access_log WHERE created_at < ?'
        );
        $stmt->execute([$before]);
        return (int)$stmt->rowCount();
    }
}
