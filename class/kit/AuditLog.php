<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * AuditLog — compliance-grade record of who changed what on any entity.
 *
 * Records every mutating operation (create/update/delete) with the actor ID,
 * entity reference, action type, and optional before/after JSON snapshots.
 * The log is append-only — no update or delete methods are provided for the
 * log entries themselves. Purging by age is available for TTL-based retention.
 *
 * Distinct from:
 * - `EventLog` — domain events (business events, not CRUD audits)
 * - `ChangeLog` — human-readable diff notes
 * - `IntegrationLog` — external API call log
 *
 * ## Usage
 *
 * ```php
 * $al = new AuditLog($pdo);
 *
 * // Record mutations
 * $al->record('user', '42', 'update', 'actor-7', ['email' => 'old@x.com'], ['email' => 'new@x.com']);
 * $al->record('user', '42', 'delete', 'actor-7');
 *
 * // Query
 * $history = $al->forEntity('user', '42');
 * $actions = $al->byActor('actor-7');
 * $recent  = $al->ofAction('delete', 50, 0);
 *
 * // Retention
 * $al->purgeOlderThan(new \DateTimeImmutable('-90 days'));
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE audit_logs (
 *     id           INTEGER PRIMARY KEY AUTOINCREMENT,
 *     entity_type  VARCHAR(100) NOT NULL,
 *     entity_id    VARCHAR(255) NOT NULL,
 *     action       VARCHAR(50)  NOT NULL,
 *     actor_id     VARCHAR(255) NOT NULL,
 *     before_data  TEXT         NULL,
 *     after_data   TEXT         NULL,
 *     ip_address   VARCHAR(45)  NULL,
 *     created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class AuditLog
{
    public const ACTION_CREATE = 'create';
    public const ACTION_UPDATE = 'update';
    public const ACTION_DELETE = 'delete';

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Append an audit record.
     *
     * @param string               $entityType  E.g. 'user', 'order'.
     * @param string               $entityId    Entity primary key.
     * @param string               $action      Action name (create/update/delete/custom).
     * @param string               $actorId     Who performed the action.
     * @param array<mixed>|null    $beforeData  State before the change (array → JSON).
     * @param array<mixed>|null    $afterData   State after the change (array → JSON).
     * @param string|null          $ipAddress   Optional remote IP.
     * @return int Row ID of the new audit entry.
     * @throws \InvalidArgumentException on empty required fields.
     */
    public function record(
        string $entityType,
        string $entityId,
        string $action,
        string $actorId,
        ?array $beforeData = null,
        ?array $afterData = null,
        ?string $ipAddress = null,
    ): int {
        $entityType = trim($entityType);
        $entityId   = trim($entityId);
        $action     = trim($action);
        $actorId    = trim($actorId);

        if ($entityType === '') {
            throw new \InvalidArgumentException('entity_type must not be empty.');
        }
        if ($entityId === '') {
            throw new \InvalidArgumentException('entity_id must not be empty.');
        }
        if ($action === '') {
            throw new \InvalidArgumentException('action must not be empty.');
        }
        if ($actorId === '') {
            throw new \InvalidArgumentException('actor_id must not be empty.');
        }

        $stmt = $this->db()->prepare(
            'INSERT INTO audit_logs (entity_type, entity_id, action, actor_id, before_data, after_data, ip_address)
             VALUES (:etype, :eid, :action, :actor, :before, :after, :ip)'
        );
        $stmt->execute([
            ':etype'  => $entityType,
            ':eid'    => $entityId,
            ':action' => $action,
            ':actor'  => $actorId,
            ':before' => $beforeData !== null ? json_encode($beforeData) : null,
            ':after'  => $afterData  !== null ? json_encode($afterData) : null,
            ':ip'     => $ipAddress,
        ]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Find a single audit entry by ID.
     *
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM audit_logs WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * List all audit entries for an entity (newest first).
     *
     * @return list<array<string,mixed>>
     */
    public function forEntity(string $entityType, string $entityId, int $limit = 100, int $offset = 0): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM audit_logs
             WHERE entity_type = :etype AND entity_id = :eid
             ORDER BY id DESC
             LIMIT :lim OFFSET :off'
        );
        $stmt->bindValue(':etype', trim($entityType));
        $stmt->bindValue(':eid', trim($entityId));
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * List audit entries by actor (newest first).
     *
     * @return list<array<string,mixed>>
     */
    public function byActor(string $actorId, int $limit = 100, int $offset = 0): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM audit_logs
             WHERE actor_id = :actor
             ORDER BY id DESC
             LIMIT :lim OFFSET :off'
        );
        $stmt->bindValue(':actor', trim($actorId));
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * List entries of a specific action type (newest first).
     *
     * @return list<array<string,mixed>>
     */
    public function ofAction(string $action, int $limit = 100, int $offset = 0): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM audit_logs
             WHERE action = :action
             ORDER BY id DESC
             LIMIT :lim OFFSET :off'
        );
        $stmt->bindValue(':action', trim($action));
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Count audit entries for an entity.
     */
    public function countForEntity(string $entityType, string $entityId): int
    {
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM audit_logs WHERE entity_type = :etype AND entity_id = :eid'
        );
        $stmt->execute([':etype' => trim($entityType), ':eid' => trim($entityId)]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Delete all audit entries older than the given cutoff (TTL retention).
     *
     * @return int Number of rows deleted.
     */
    public function purgeOlderThan(\DateTimeImmutable $cutoff): int
    {
        $stmt = $this->db()->prepare('DELETE FROM audit_logs WHERE created_at < :cutoff');
        $stmt->execute([':cutoff' => $cutoff->format('Y-m-d H:i:s')]);
        return $stmt->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
