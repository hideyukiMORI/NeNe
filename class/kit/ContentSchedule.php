<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * ContentSchedule — schedule content for future publish and optional expiry.
 *
 * Useful for blog posts, banners, announcements, and any content that should
 * be visible only within a specific time window.
 *
 * Status transitions:
 * - `draft`     → not yet published (publish_at in the future, or publish_at null)
 * - `published` → publish_at ≤ now AND (expire_at IS NULL OR expire_at > now)
 * - `expired`   → expire_at ≤ now
 * - `cancelled` → explicitly cancelled (removed from all public queries)
 *
 * ## Usage
 *
 * ```php
 * $cs = new ContentSchedule($pdo);
 *
 * // Schedule a post
 * $id = $cs->schedule('post', '42', new \DateTimeImmutable('+1 hour'));
 *
 * // Publish immediately
 * $id = $cs->schedule('banner', '7', new \DateTimeImmutable());
 *
 * // Check if live
 * $cs->isLive('post', '42'); // true/false
 *
 * // Fetch all currently live items for a type
 * $live = $cs->listLive('post');
 *
 * // Cancel
 * $cs->cancel('post', '42');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE content_schedules (
 *     id           INTEGER PRIMARY KEY AUTOINCREMENT,
 *     entity_type  VARCHAR(100) NOT NULL,
 *     entity_id    VARCHAR(255) NOT NULL,
 *     publish_at   DATETIME     NOT NULL,
 *     expire_at    DATETIME     DEFAULT NULL,
 *     cancelled_at DATETIME     DEFAULT NULL,
 *     created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (entity_type, entity_id)
 * );
 * ```
 */
final class ContentSchedule
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Schedule content for publication.
     *
     * If an entry already exists for (entity_type, entity_id), it is replaced.
     *
     * @param  \DateTimeImmutable      $publishAt  When to make the content live.
     * @param  \DateTimeImmutable|null $expireAt   Optional expiry time.
     * @return int  The schedule record ID.
     * @throws \InvalidArgumentException if entity_type or entity_id is empty.
     * @throws \InvalidArgumentException if expire_at is not after publish_at.
     */
    public function schedule(
        string $entityType,
        string $entityId,
        \DateTimeImmutable $publishAt,
        ?\DateTimeImmutable $expireAt = null
    ): int {
        [$entityType, $entityId] = $this->normalise($entityType, $entityId);

        if ($expireAt !== null && $expireAt <= $publishAt) {
            throw new \InvalidArgumentException('expire_at must be after publish_at.');
        }

        $db     = $this->db();
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);

        $publishStr = $publishAt->format('Y-m-d H:i:s');
        $expireStr  = $expireAt?->format('Y-m-d H:i:s');

        if ($driver === 'sqlite') {
            $db->prepare(
                'INSERT OR REPLACE INTO content_schedules
                 (entity_type, entity_id, publish_at, expire_at, cancelled_at)
                 VALUES (:type, :id, :pub, :exp, NULL)'
            )->execute([':type' => $entityType, ':id' => $entityId, ':pub' => $publishStr, ':exp' => $expireStr]);
        } else {
            $db->prepare(
                'INSERT INTO content_schedules (entity_type, entity_id, publish_at, expire_at, cancelled_at)
                 VALUES (:type, :id, :pub, :exp, NULL)
                 ON DUPLICATE KEY UPDATE publish_at = VALUES(publish_at),
                                         expire_at  = VALUES(expire_at),
                                         cancelled_at = NULL'
            )->execute([':type' => $entityType, ':id' => $entityId, ':pub' => $publishStr, ':exp' => $expireStr]);
        }

        $stmt = $db->prepare(
            'SELECT id FROM content_schedules WHERE entity_type = :type AND entity_id = :id LIMIT 1'
        );
        $stmt->execute([':type' => $entityType, ':id' => $entityId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Check whether the content is currently live (published and not expired/cancelled).
     */
    public function isLive(string $entityType, string $entityId): bool
    {
        [$entityType, $entityId] = $this->normalise($entityType, $entityId);
        $now  = $this->nowString();
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM content_schedules
             WHERE entity_type = :type
               AND entity_id   = :id
               AND publish_at  <= :now
               AND (expire_at IS NULL OR expire_at > :now2)
               AND cancelled_at IS NULL'
        );
        $stmt->execute([':type' => $entityType, ':id' => $entityId, ':now' => $now, ':now2' => $now]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Get the current status of a scheduled entry.
     *
     * @return 'draft'|'published'|'expired'|'cancelled'|null  null if not found.
     */
    public function status(string $entityType, string $entityId): ?string
    {
        [$entityType, $entityId] = $this->normalise($entityType, $entityId);
        $stmt = $this->db()->prepare(
            'SELECT publish_at, expire_at, cancelled_at
             FROM content_schedules
             WHERE entity_type = :type AND entity_id = :id LIMIT 1'
        );
        $stmt->execute([':type' => $entityType, ':id' => $entityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return $this->computeStatus($row);
    }

    /**
     * List all currently live entities of a given type.
     *
     * @return list<array{id: int, entity_type: string, entity_id: string, publish_at: string, expire_at: string|null}>
     */
    public function listLive(string $entityType): array
    {
        $entityType = trim($entityType);
        $now        = $this->nowString();
        $stmt       = $this->db()->prepare(
            'SELECT id, entity_type, entity_id, publish_at, expire_at
             FROM content_schedules
             WHERE entity_type = :type
               AND publish_at  <= :now
               AND (expire_at IS NULL OR expire_at > :now2)
               AND cancelled_at IS NULL
             ORDER BY publish_at ASC'
        );
        $stmt->execute([':type' => $entityType, ':now' => $now, ':now2' => $now]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * List all scheduled (not-yet-live) entries of a given type.
     *
     * @return list<array{id: int, entity_type: string, entity_id: string, publish_at: string, expire_at: string|null}>
     */
    public function listScheduled(string $entityType): array
    {
        $entityType = trim($entityType);
        $now        = $this->nowString();
        $stmt       = $this->db()->prepare(
            'SELECT id, entity_type, entity_id, publish_at, expire_at
             FROM content_schedules
             WHERE entity_type = :type
               AND publish_at  > :now
               AND cancelled_at IS NULL
             ORDER BY publish_at ASC'
        );
        $stmt->execute([':type' => $entityType, ':now' => $now]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Cancel a scheduled entry.
     *
     * @return bool True if a non-cancelled entry was found and cancelled.
     */
    public function cancel(string $entityType, string $entityId): bool
    {
        [$entityType, $entityId] = $this->normalise($entityType, $entityId);
        $stmt = $this->db()->prepare(
            'UPDATE content_schedules
             SET cancelled_at = CURRENT_TIMESTAMP
             WHERE entity_type = :type AND entity_id = :id AND cancelled_at IS NULL'
        );
        $stmt->execute([':type' => $entityType, ':id' => $entityId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Update the publish/expire times for an existing entry.
     *
     * @throws \InvalidArgumentException if the entry does not exist or is cancelled.
     * @throws \InvalidArgumentException if expire_at is not after publish_at.
     */
    public function reschedule(
        string $entityType,
        string $entityId,
        \DateTimeImmutable $publishAt,
        ?\DateTimeImmutable $expireAt = null
    ): bool {
        [$entityType, $entityId] = $this->normalise($entityType, $entityId);

        if ($expireAt !== null && $expireAt <= $publishAt) {
            throw new \InvalidArgumentException('expire_at must be after publish_at.');
        }

        $publishStr = $publishAt->format('Y-m-d H:i:s');
        $expireStr  = $expireAt?->format('Y-m-d H:i:s');

        $stmt = $this->db()->prepare(
            'UPDATE content_schedules
             SET publish_at = :pub, expire_at = :exp
             WHERE entity_type = :type AND entity_id = :id AND cancelled_at IS NULL'
        );
        $stmt->execute([':pub' => $publishStr, ':exp' => $expireStr, ':type' => $entityType, ':id' => $entityId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Remove a schedule entry entirely (hard delete).
     *
     * @return bool True if a row was deleted.
     */
    public function remove(string $entityType, string $entityId): bool
    {
        [$entityType, $entityId] = $this->normalise($entityType, $entityId);
        $stmt = $this->db()->prepare(
            'DELETE FROM content_schedules WHERE entity_type = :type AND entity_id = :id'
        );
        $stmt->execute([':type' => $entityType, ':id' => $entityId]);
        return $stmt->rowCount() > 0;
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    private function nowString(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }

    /**
     * @param array<string,mixed> $row
     */
    private function computeStatus(array $row): string
    {
        if ($row['cancelled_at'] !== null) {
            return 'cancelled';
        }
        $now = $this->nowString();
        if ($row['expire_at'] !== null && $row['expire_at'] <= $now) {
            return 'expired';
        }
        if ($row['publish_at'] <= $now) {
            return 'published';
        }
        return 'draft';
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
