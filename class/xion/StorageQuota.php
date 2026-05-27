<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * StorageQuota — per-owner storage usage tracking with configurable limits.
 *
 * Tracks bytes used by each owner (user, organisation, bucket) and enforces
 * a maximum quota. Usage is stored as an aggregate value and updated atomically.
 *
 * ## Usage
 *
 * ```php
 * $sq = new StorageQuota($pdo);
 *
 * // Set a quota for a user
 * $sq->setLimit('user', 'user-1', 1_073_741_824); // 1 GiB
 *
 * // Record a file upload (add bytes)
 * $sq->add('user', 'user-1', 5_242_880); // +5 MiB
 *
 * // Check before uploading
 * if (!$sq->wouldExceed('user', 'user-1', $fileSize)) {
 *     // proceed
 * }
 *
 * // Get current usage
 * $info = $sq->usage('user', 'user-1');
 * // ['used' => 5242880, 'limit' => 1073741824, 'available' => 1068498944]
 *
 * // Release bytes on delete
 * $sq->release('user', 'user-1', 5_242_880);
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE storage_quotas (
 *     owner_type  VARCHAR(100) NOT NULL,
 *     owner_id    VARCHAR(255) NOT NULL,
 *     used_bytes  BIGINT       NOT NULL DEFAULT 0,
 *     limit_bytes BIGINT       DEFAULT NULL,
 *     updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     PRIMARY KEY (owner_type, owner_id)
 * );
 * ```
 */
final class StorageQuota
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Set (or update) the storage limit for an owner.
     *
     * Pass null to remove the limit (unlimited).
     *
     * @throws \InvalidArgumentException if owner_type or owner_id is empty.
     * @throws \InvalidArgumentException if limit_bytes is negative.
     */
    public function setLimit(string $ownerType, string $ownerId, ?int $limitBytes): void
    {
        [$ownerType, $ownerId] = $this->normalise($ownerType, $ownerId);
        if ($limitBytes !== null && $limitBytes < 0) {
            throw new \InvalidArgumentException('limit_bytes must be non-negative.');
        }

        $db     = $this->db();
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $db->prepare(
                'INSERT INTO storage_quotas (owner_type, owner_id, limit_bytes)
                 VALUES (:type, :id, :limit)
                 ON CONFLICT (owner_type, owner_id) DO UPDATE SET limit_bytes = :limit2, updated_at = CURRENT_TIMESTAMP'
            )->execute([':type' => $ownerType, ':id' => $ownerId, ':limit' => $limitBytes, ':limit2' => $limitBytes]);
        } else {
            $db->prepare(
                'INSERT INTO storage_quotas (owner_type, owner_id, limit_bytes)
                 VALUES (:type, :id, :limit)
                 ON DUPLICATE KEY UPDATE limit_bytes = VALUES(limit_bytes), updated_at = CURRENT_TIMESTAMP'
            )->execute([':type' => $ownerType, ':id' => $ownerId, ':limit' => $limitBytes]);
        }
    }

    /**
     * Add bytes to an owner's usage (e.g., after a file upload).
     *
     * Creates the quota record if it does not exist (no limit set — unlimited).
     *
     * @throws \InvalidArgumentException if bytes is not positive.
     * @throws \OverflowException if adding bytes would exceed the set limit.
     */
    public function add(string $ownerType, string $ownerId, int $bytes): void
    {
        [$ownerType, $ownerId] = $this->normalise($ownerType, $ownerId);
        if ($bytes <= 0) {
            throw new \InvalidArgumentException('bytes must be positive.');
        }

        if ($this->wouldExceed($ownerType, $ownerId, $bytes)) {
            throw new \OverflowException('Storage quota would be exceeded.');
        }

        $db     = $this->db();
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $db->prepare(
                'INSERT INTO storage_quotas (owner_type, owner_id, used_bytes)
                 VALUES (:type, :id, :bytes)
                 ON CONFLICT (owner_type, owner_id) DO UPDATE SET used_bytes = used_bytes + :bytes2, updated_at = CURRENT_TIMESTAMP'
            )->execute([':type' => $ownerType, ':id' => $ownerId, ':bytes' => $bytes, ':bytes2' => $bytes]);
        } else {
            $db->prepare(
                'INSERT INTO storage_quotas (owner_type, owner_id, used_bytes)
                 VALUES (:type, :id, :bytes)
                 ON DUPLICATE KEY UPDATE used_bytes = used_bytes + VALUES(used_bytes), updated_at = CURRENT_TIMESTAMP'
            )->execute([':type' => $ownerType, ':id' => $ownerId, ':bytes' => $bytes]);
        }
    }

    /**
     * Release bytes from an owner's usage (e.g., after a file deletion).
     *
     * Usage will not go below zero.
     *
     * @throws \InvalidArgumentException if bytes is not positive.
     */
    public function release(string $ownerType, string $ownerId, int $bytes): void
    {
        [$ownerType, $ownerId] = $this->normalise($ownerType, $ownerId);
        if ($bytes <= 0) {
            throw new \InvalidArgumentException('bytes must be positive.');
        }

        $this->db()->prepare(
            'UPDATE storage_quotas
             SET used_bytes = MAX(0, used_bytes - :bytes), updated_at = CURRENT_TIMESTAMP
             WHERE owner_type = :type AND owner_id = :id'
        )->execute([':bytes' => $bytes, ':type' => $ownerType, ':id' => $ownerId]);
    }

    /**
     * Return current usage info for an owner.
     *
     * Returns null if no record exists (owner has never used storage).
     *
     * @return array{used: int, limit: int|null, available: int|null}|null
     */
    public function usage(string $ownerType, string $ownerId): ?array
    {
        [$ownerType, $ownerId] = $this->normalise($ownerType, $ownerId);
        $stmt = $this->db()->prepare(
            'SELECT used_bytes, limit_bytes FROM storage_quotas
             WHERE owner_type = :type AND owner_id = :id LIMIT 1'
        );
        $stmt->execute([':type' => $ownerType, ':id' => $ownerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        $used  = (int)$row['used_bytes'];
        $limit = $row['limit_bytes'] !== null ? (int)$row['limit_bytes'] : null;

        return [
            'used'      => $used,
            'limit'     => $limit,
            'available' => $limit !== null ? max(0, $limit - $used) : null,
        ];
    }

    /**
     * Check whether adding `$bytes` would exceed the quota.
     *
     * Returns false if no limit is set (unlimited).
     */
    public function wouldExceed(string $ownerType, string $ownerId, int $bytes): bool
    {
        $info = $this->usage($ownerType, $ownerId);
        if ($info === null || $info['limit'] === null) {
            return false;
        }
        return ($info['used'] + $bytes) > $info['limit'];
    }

    /**
     * Get the percentage of quota used (0–100).
     *
     * Returns null if no limit is set or owner has no record.
     */
    public function percentUsed(string $ownerType, string $ownerId): ?float
    {
        $info = $this->usage($ownerType, $ownerId);
        if ($info === null || $info['limit'] === null || $info['limit'] === 0) {
            return null;
        }
        return round(($info['used'] / $info['limit']) * 100, 2);
    }

    /**
     * Reset usage to zero (e.g., on account purge).
     */
    public function reset(string $ownerType, string $ownerId): bool
    {
        [$ownerType, $ownerId] = $this->normalise($ownerType, $ownerId);
        $stmt = $this->db()->prepare(
            'UPDATE storage_quotas SET used_bytes = 0, updated_at = CURRENT_TIMESTAMP
             WHERE owner_type = :type AND owner_id = :id'
        );
        $stmt->execute([':type' => $ownerType, ':id' => $ownerId]);
        return $stmt->rowCount() > 0;
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    /**
     * @return array{string, string}
     */
    private function normalise(string $ownerType, string $ownerId): array
    {
        $ownerType = trim($ownerType);
        $ownerId   = trim($ownerId);
        if ($ownerType === '') {
            throw new \InvalidArgumentException('owner_type must not be empty.');
        }
        if ($ownerId === '') {
            throw new \InvalidArgumentException('owner_id must not be empty.');
        }
        return [$ownerType, $ownerId];
    }
}
