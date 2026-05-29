<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * ContentVersion — content versioning with rollback support.
 *
 * Stores an append-only history of body revisions for any entity.
 * Each save creates a new immutable version row. The current version is
 * always the highest version number. Rollback creates a new version
 * (rather than deleting history).
 *
 * ## Usage
 *
 * ```php
 * $cv = new ContentVersion($pdo);
 *
 * // Save first version
 * $v1 = $cv->save('post', '42', 'Hello world', 'user-1');  // returns 1
 *
 * // Save revision
 * $v2 = $cv->save('post', '42', 'Hello, world!', 'user-1');  // returns 2
 *
 * // Get latest
 * $cv->get('post', '42');           // version 2
 *
 * // Get specific version
 * $cv->get('post', '42', 1);        // version 1
 *
 * // Full history
 * $cv->history('post', '42');       // [{version:1,...}, {version:2,...}]
 *
 * // Rollback to v1 (creates v3 with v1's body)
 * $cv->rollback('post', '42', 1);
 *
 * // Prune old versions
 * $cv->purgeOlderVersions('post', '42', keepCount: 10);
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE content_versions (
 *     id           INTEGER PRIMARY KEY AUTOINCREMENT,
 *     entity_type  VARCHAR(100) NOT NULL,
 *     entity_id    VARCHAR(255) NOT NULL,
 *     version      INTEGER      NOT NULL,
 *     body         TEXT         NOT NULL DEFAULT '',
 *     author_id    VARCHAR(255) NOT NULL DEFAULT '',
 *     created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (entity_type, entity_id, version)
 * );
 * ```
 */
final class ContentVersion
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Save a new version of an entity's content.
     *
     * The version number is derived from the current maximum + 1.
     *
     * @return int The new version number.
     * @throws \InvalidArgumentException if entity_type or entity_id is empty.
     */
    public function save(string $entityType, string $entityId, string $body, string $authorId = ''): int
    {
        [$entityType, $entityId] = $this->normalise($entityType, $entityId);

        $db   = $this->db();
        $stmt = $db->prepare(
            'SELECT COALESCE(MAX(version), 0) FROM content_versions
             WHERE entity_type = :type AND entity_id = :id'
        );
        $stmt->execute([':type' => $entityType, ':id' => $entityId]);
        $nextVersion = (int)$stmt->fetchColumn() + 1;

        $db->prepare(
            'INSERT INTO content_versions (entity_type, entity_id, version, body, author_id)
             VALUES (:type, :id, :ver, :body, :author)'
        )->execute([
            ':type'   => $entityType,
            ':id'     => $entityId,
            ':ver'    => $nextVersion,
            ':body'   => $body,
            ':author' => $authorId,
        ]);

        return $nextVersion;
    }

    /**
     * Retrieve a specific version (or the latest if version is null).
     *
     * @return array<string,mixed>|null null if not found.
     */
    public function get(string $entityType, string $entityId, ?int $version = null): ?array
    {
        [$entityType, $entityId] = $this->normalise($entityType, $entityId);

        if ($version !== null) {
            $stmt = $this->db()->prepare(
                'SELECT id, entity_type, entity_id, version, body, author_id, created_at
                 FROM content_versions
                 WHERE entity_type = :type AND entity_id = :id AND version = :ver
                 LIMIT 1'
            );
            $stmt->execute([':type' => $entityType, ':id' => $entityId, ':ver' => $version]);
        } else {
            $stmt = $this->db()->prepare(
                'SELECT id, entity_type, entity_id, version, body, author_id, created_at
                 FROM content_versions
                 WHERE entity_type = :type AND entity_id = :id
                 ORDER BY version DESC LIMIT 1'
            );
            $stmt->execute([':type' => $entityType, ':id' => $entityId]);
        }

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Return the full version history for an entity, oldest first.
     *
     * @return list<array<string,mixed>>
     */
    public function history(string $entityType, string $entityId): array
    {
        [$entityType, $entityId] = $this->normalise($entityType, $entityId);
        $stmt = $this->db()->prepare(
            'SELECT id, entity_type, entity_id, version, body, author_id, created_at
             FROM content_versions
             WHERE entity_type = :type AND entity_id = :id
             ORDER BY version ASC'
        );
        $stmt->execute([':type' => $entityType, ':id' => $entityId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Rollback to a previous version by creating a new version with that body.
     *
     * @return int The new version number, or 0 if the target version was not found.
     */
    public function rollback(string $entityType, string $entityId, int $toVersion, string $authorId = ''): int
    {
        $target = $this->get($entityType, $entityId, $toVersion);
        if ($target === null) {
            return 0;
        }
        return $this->save($entityType, $entityId, (string)$target['body'], $authorId);
    }

    /**
     * Count versions for an entity.
     */
    public function count(string $entityType, string $entityId): int
    {
        [$entityType, $entityId] = $this->normalise($entityType, $entityId);
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM content_versions WHERE entity_type = :type AND entity_id = :id'
        );
        $stmt->execute([':type' => $entityType, ':id' => $entityId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Keep only the most recent N versions; hard-delete older ones.
     *
     * @param int $keepCount Number of latest versions to retain.
     * @return int Number of rows deleted.
     */
    public function purgeOlderVersions(string $entityType, string $entityId, int $keepCount): int
    {
        [$entityType, $entityId] = $this->normalise($entityType, $entityId);
        $keepCount = max(1, $keepCount);

        $total = $this->count($entityType, $entityId);
        if ($total <= $keepCount) {
            return 0;
        }

        // Find the minimum version to keep
        $stmt = $this->db()->prepare(
            "SELECT version FROM content_versions
             WHERE entity_type = :type AND entity_id = :id
             ORDER BY version DESC
             LIMIT {$keepCount}"
        );
        $stmt->execute([':type' => $entityType, ':id' => $entityId]);
        $rows    = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $minKeep = (int)end($rows);

        $del = $this->db()->prepare(
            'DELETE FROM content_versions
             WHERE entity_type = :type AND entity_id = :id AND version < :min'
        );
        $del->execute([':type' => $entityType, ':id' => $entityId, ':min' => $minKeep]);
        return $del->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    /**
     * @return array{string, string}
     */
    private function normalise(string $entityType, string $entityId): array
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
