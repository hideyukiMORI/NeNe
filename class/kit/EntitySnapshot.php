<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * EntitySnapshot — point-in-time entity state snapshots.
 *
 * Stores full JSON state snapshots of entities at specific points in time
 * (e.g. before a major change, on each publish, or at regular intervals).
 * Useful for "restore to version" workflows, compliance archiving, and
 * diff-free before/after comparisons.
 *
 * Distinct from AuditLog (which records before/after diffs for individual
 * changes); EntitySnapshot stores the complete entity state for retrieval
 * and restore.
 *
 * ## Usage
 *
 * ```php
 * $es = new EntitySnapshot($pdo);
 *
 * // Save on each publish
 * $id = $es->save('article', '42', $articleData, 'v2');
 *
 * // Latest snapshot
 * $latest = $es->latest('article', '42');
 *
 * // Find snapshot nearest to a datetime
 * $snap = $es->findAt('article', '42', '2026-04-15 12:00:00');
 *
 * // Restore: get data from a specific version label
 * $v1 = $es->findByLabel('article', '42', 'v1');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE entity_snapshots (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     entity_type VARCHAR(100) NOT NULL,
 *     entity_id   VARCHAR(255) NOT NULL,
 *     label       VARCHAR(100) NULL,
 *     data        TEXT         NOT NULL,
 *     created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * CREATE INDEX idx_entity_snapshots_lookup ON entity_snapshots (entity_type, entity_id, created_at);
 * ```
 */
final class EntitySnapshot
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Save a snapshot of an entity's current state.
     *
     * @param array<string,mixed> $data          JSON-serialisable entity state.
     * @param string|null         $label         Optional version label (e.g. 'v1', 'pre-merge').
     * @return int New snapshot ID.
     * @throws \InvalidArgumentException on empty entityType/entityId or empty data.
     */
    public function save(
        string $entityType,
        string $entityId,
        array $data,
        ?string $label = null
    ): int {
        [$entityType, $entityId] = $this->validateEntity($entityType, $entityId);
        if (empty($data)) {
            throw new \InvalidArgumentException('data must not be empty.');
        }

        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'INSERT INTO entity_snapshots (entity_type, entity_id, label, data, created_at)
             VALUES (:type, :eid, :label, :data, :now)'
        );
        $stmt->execute([
            ':type'  => $entityType,
            ':eid'   => $entityId,
            ':label' => $label !== null ? trim($label) : null,
            ':data'  => json_encode($data, JSON_UNESCAPED_UNICODE),
            ':now'   => $now,
        ]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Retrieve a snapshot by ID.
     *
     * @return array<string,mixed>|null
     */
    public function get(int $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM entity_snapshots WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Return the most recent snapshot for an entity.
     *
     * @return array<string,mixed>|null
     */
    public function latest(string $entityType, string $entityId): ?array
    {
        [$entityType, $entityId] = $this->validateEntity($entityType, $entityId);
        $stmt                    = $this->db()->prepare(
            'SELECT * FROM entity_snapshots
             WHERE entity_type = :type AND entity_id = :eid
             ORDER BY id DESC
             LIMIT 1'
        );
        $stmt->execute([':type' => $entityType, ':eid' => $entityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Return the snapshot closest to (but not after) a given datetime.
     *
     * @return array<string,mixed>|null
     */
    public function findAt(string $entityType, string $entityId, string $datetime): ?array
    {
        [$entityType, $entityId] = $this->validateEntity($entityType, $entityId);
        $stmt                    = $this->db()->prepare(
            'SELECT * FROM entity_snapshots
             WHERE entity_type = :type AND entity_id = :eid AND created_at <= :dt
             ORDER BY created_at DESC, id DESC
             LIMIT 1'
        );
        $stmt->execute([':type' => $entityType, ':eid' => $entityId, ':dt' => $datetime]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Return the snapshot with a specific label.
     *
     * @return array<string,mixed>|null
     */
    public function findByLabel(string $entityType, string $entityId, string $label): ?array
    {
        [$entityType, $entityId] = $this->validateEntity($entityType, $entityId);
        $label                   = trim($label);
        $stmt                    = $this->db()->prepare(
            'SELECT * FROM entity_snapshots
             WHERE entity_type = :type AND entity_id = :eid AND label = :label
             ORDER BY id DESC
             LIMIT 1'
        );
        $stmt->execute([':type' => $entityType, ':eid' => $entityId, ':label' => $label]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * List all snapshots for an entity, newest first.
     *
     * @return list<array<string,mixed>>
     */
    public function list(string $entityType, string $entityId, int $limit = 20): array
    {
        [$entityType, $entityId] = $this->validateEntity($entityType, $entityId);
        $stmt                    = $this->db()->prepare(
            'SELECT id, entity_type, entity_id, label, created_at
             FROM entity_snapshots
             WHERE entity_type = :type AND entity_id = :eid
             ORDER BY id DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':type', $entityType, PDO::PARAM_STR);
        $stmt->bindValue(':eid', $entityId, PDO::PARAM_STR);
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Delete a single snapshot.
     *
     * @return bool True if found and deleted.
     */
    public function delete(int $id): bool
    {
        $stmt = $this->db()->prepare('DELETE FROM entity_snapshots WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Delete snapshots older than $cutoff for an entity, keeping the latest $keep.
     *
     * @return int Number of rows deleted.
     */
    public function purgeOlderThan(string $entityType, string $entityId, string $cutoff): int
    {
        [$entityType, $entityId] = $this->validateEntity($entityType, $entityId);
        $stmt                    = $this->db()->prepare(
            'DELETE FROM entity_snapshots
             WHERE entity_type = :type AND entity_id = :eid AND created_at < :cutoff'
        );
        $stmt->execute([':type' => $entityType, ':eid' => $entityId, ':cutoff' => $cutoff]);
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
}
