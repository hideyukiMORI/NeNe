<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * ResourceLock — advisory lock on any entity to prevent concurrent edits.
 *
 * Provides optimistic advisory locking: a user can claim a lock on a named
 * resource. The lock has a TTL (time-to-live in seconds) so stale locks are
 * automatically released. Only the lock holder can release their lock.
 *
 * This is an advisory lock — it does not prevent concurrent DB writes.
 * Application code must check acquire() before editing, and release() when done.
 *
 * ## Usage
 *
 * ```php
 * $rl = new ResourceLock($pdo);
 *
 * // Try to claim
 * $id = $rl->acquire('document', '42', 'user-7', 300);  // 5 min TTL
 * if ($id === null) {
 *     // someone else holds the lock
 * }
 *
 * // Check who holds it
 * $lock = $rl->current('document', '42');
 *
 * // Release
 * $rl->release('document', '42', 'user-7');
 *
 * // Extend TTL
 * $rl->extend($id, 300);
 *
 * // Force-release stale locks (cron job)
 * $rl->releaseExpired();
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE resource_locks (
 *     id            INTEGER PRIMARY KEY AUTOINCREMENT,
 *     resource_type VARCHAR(100) NOT NULL,
 *     resource_id   VARCHAR(255) NOT NULL,
 *     holder_id     VARCHAR(255) NOT NULL,
 *     expires_at    DATETIME     NOT NULL,
 *     created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (resource_type, resource_id)
 * );
 * ```
 */
final class ResourceLock
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Acquire the lock on a resource for a holder.
     *
     * Returns the lock ID on success. Returns null if the resource is already
     * locked by someone else and the lock has not expired.
     *
     * @param int $ttlSeconds Lock time-to-live in seconds.
     * @return int|null Lock ID on success; null if lock is held by another.
     * @throws \InvalidArgumentException on invalid arguments.
     */
    public function acquire(
        string $resourceType,
        string $resourceId,
        string $holderId,
        int $ttlSeconds = 300
    ): ?int {
        [$resourceType, $resourceId] = $this->validateResource($resourceType, $resourceId);
        $holderId = trim($holderId);
        if ($holderId === '') {
            throw new \InvalidArgumentException('holderId must not be empty.');
        }
        if ($ttlSeconds <= 0) {
            throw new \InvalidArgumentException('ttlSeconds must be > 0.');
        }

        $now       = new \DateTimeImmutable();
        $nowStr    = $now->format('Y-m-d H:i:s');
        $expiresAt = $now->modify("+{$ttlSeconds} seconds")->format('Y-m-d H:i:s');

        // Check existing lock
        $existing = $this->current($resourceType, $resourceId);
        if ($existing !== null) {
            // If the holder is the same user, renew
            if ($existing['holder_id'] === $holderId) {
                $stmt = $this->db()->prepare(
                    'UPDATE resource_locks SET expires_at = :exp WHERE id = :id'
                );
                $stmt->execute([':exp' => $expiresAt, ':id' => (int)$existing['id']]);
                return (int)$existing['id'];
            }
            return null;
        }

        // No active lock — insert
        try {
            $stmt = $this->db()->prepare(
                'INSERT INTO resource_locks (resource_type, resource_id, holder_id, expires_at, created_at)
                 VALUES (:type, :rid, :holder, :exp, :now)'
            );
            $stmt->execute([
                ':type'   => $resourceType,
                ':rid'    => $resourceId,
                ':holder' => $holderId,
                ':exp'    => $expiresAt,
                ':now'    => $nowStr,
            ]);
            return (int)$this->db()->lastInsertId();
        } catch (\PDOException) {
            // Race condition — another process grabbed it just now
            return null;
        }
    }

    /**
     * Find the current (non-expired) lock on a resource.
     *
     * @return array<string,mixed>|null null if no active lock.
     */
    public function current(string $resourceType, string $resourceId): ?array
    {
        [$resourceType, $resourceId] = $this->validateResource($resourceType, $resourceId);
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'SELECT * FROM resource_locks
             WHERE resource_type = :type AND resource_id = :rid AND expires_at > :now'
        );
        $stmt->execute([':type' => $resourceType, ':rid' => $resourceId, ':now' => $now]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Check whether a resource is currently locked by a specific holder.
     */
    public function isHeldBy(string $resourceType, string $resourceId, string $holderId): bool
    {
        $lock = $this->current($resourceType, $resourceId);
        return $lock !== null && $lock['holder_id'] === trim($holderId);
    }

    /**
     * Release a lock — only the holder can release.
     *
     * @return bool True if the lock was found and released by this holder.
     */
    public function release(string $resourceType, string $resourceId, string $holderId): bool
    {
        [$resourceType, $resourceId] = $this->validateResource($resourceType, $resourceId);
        $stmt = $this->db()->prepare(
            'DELETE FROM resource_locks
             WHERE resource_type = :type AND resource_id = :rid AND holder_id = :holder'
        );
        $stmt->execute([
            ':type'   => $resourceType,
            ':rid'    => $resourceId,
            ':holder' => trim($holderId),
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Force-release a lock by its ID (admin / cron use).
     *
     * @return bool True if found and deleted.
     */
    public function forceRelease(int $id): bool
    {
        $stmt = $this->db()->prepare('DELETE FROM resource_locks WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Extend the TTL of an existing lock (holder must match).
     *
     * @return bool True if found, holder matches, and not expired.
     */
    public function extend(int $id, string $holderId, int $ttlSeconds): bool
    {
        $now       = new \DateTimeImmutable();
        $expiresAt = $now->modify("+{$ttlSeconds} seconds")->format('Y-m-d H:i:s');
        $nowStr    = $now->format('Y-m-d H:i:s');

        $stmt = $this->db()->prepare(
            'UPDATE resource_locks SET expires_at = :exp
             WHERE id = :id AND holder_id = :holder AND expires_at > :now'
        );
        $stmt->execute([
            ':exp'    => $expiresAt,
            ':id'     => $id,
            ':holder' => trim($holderId),
            ':now'    => $nowStr,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Delete all expired locks (for cron-based cleanup).
     *
     * @return int Number of rows deleted.
     */
    public function releaseExpired(): int
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare('DELETE FROM resource_locks WHERE expires_at <= :now');
        $stmt->execute([':now' => $now]);
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
    private function validateResource(string $resourceType, string $resourceId): array
    {
        $resourceType = trim($resourceType);
        $resourceId   = trim($resourceId);
        if ($resourceType === '') {
            throw new \InvalidArgumentException('resource_type must not be empty.');
        }
        if ($resourceId === '') {
            throw new \InvalidArgumentException('resource_id must not be empty.');
        }
        return [$resourceType, $resourceId];
    }
}
