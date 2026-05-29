<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * DocumentLock — optimistic editing lock for collaborative document editing.
 *
 * Prevents multiple users from simultaneously editing the same document.
 * A user acquires a lock before editing; the lock has a TTL and must be
 * periodically refreshed. Another user cannot acquire the lock while it is
 * active. The owner may release it explicitly when done.
 *
 * ## Usage
 *
 * ```php
 * $dl = new DocumentLock($pdo);
 *
 * // Acquire
 * $ok = $dl->acquire('post', '42', 'user-1', ttlSeconds: 300);
 * if (!$ok) {
 *     $holder = $dl->holder('post', '42');
 *     // → "locked by user-{$holder['user_id']}"
 * }
 *
 * // Keep alive
 * $dl->refresh('post', '42', 'user-1', ttlSeconds: 300);
 *
 * // Release
 * $dl->release('post', '42', 'user-1');
 *
 * // Maintenance
 * $dl->releaseExpired();
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE document_locks (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     entity_type VARCHAR(100) NOT NULL,
 *     entity_id   VARCHAR(255) NOT NULL,
 *     user_id     VARCHAR(255) NOT NULL,
 *     expires_at  DATETIME     NOT NULL,
 *     acquired_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (entity_type, entity_id)
 * );
 * ```
 */
final class DocumentLock
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Try to acquire the lock for a document.
     *
     * Returns true if the lock was granted (no active lock exists, or the
     * caller already holds it). Returns false if another user holds an active lock.
     *
     * @param int $ttlSeconds Lock duration in seconds.
     * @throws \InvalidArgumentException on empty inputs.
     */
    public function acquire(string $entityType, string $entityId, string $userId, int $ttlSeconds = 300): bool
    {
        [$entityType, $entityId, $userId] = $this->validateInputs($entityType, $entityId, $userId);
        $now       = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $expiresAt = (new \DateTimeImmutable())->modify("+{$ttlSeconds} seconds")->format('Y-m-d H:i:s');
        $db        = $this->db();

        // Check for an active lock held by someone else
        $stmt = $db->prepare(
            'SELECT user_id FROM document_locks
             WHERE entity_type = :type AND entity_id = :id AND expires_at > :now
             LIMIT 1'
        );
        $stmt->execute([':type' => $entityType, ':id' => $entityId, ':now' => $now]);
        $existing = $stmt->fetchColumn();

        if ($existing !== false && $existing !== $userId) {
            return false; // locked by someone else
        }

        // Upsert (own or expired lock)
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $db->prepare(
                'INSERT INTO document_locks (entity_type, entity_id, user_id, expires_at)
                 VALUES (:type, :id, :uid, :exp)
                 ON CONFLICT (entity_type, entity_id)
                 DO UPDATE SET user_id     = excluded.user_id,
                               expires_at  = excluded.expires_at,
                               acquired_at = CURRENT_TIMESTAMP'
            )->execute([':type' => $entityType, ':id' => $entityId, ':uid' => $userId, ':exp' => $expiresAt]);
        } else {
            $db->prepare(
                'INSERT INTO document_locks (entity_type, entity_id, user_id, expires_at)
                 VALUES (:type, :id, :uid, :exp)
                 ON DUPLICATE KEY UPDATE user_id     = VALUES(user_id),
                                         expires_at  = VALUES(expires_at),
                                         acquired_at = CURRENT_TIMESTAMP'
            )->execute([':type' => $entityType, ':id' => $entityId, ':uid' => $userId, ':exp' => $expiresAt]);
        }

        return true;
    }

    /**
     * Refresh (extend) the lock TTL. Only the current holder can refresh.
     *
     * @return bool True if the lock was refreshed.
     */
    public function refresh(string $entityType, string $entityId, string $userId, int $ttlSeconds = 300): bool
    {
        [$entityType, $entityId, $userId] = $this->validateInputs($entityType, $entityId, $userId);
        $now       = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $expiresAt = (new \DateTimeImmutable())->modify("+{$ttlSeconds} seconds")->format('Y-m-d H:i:s');

        $stmt = $this->db()->prepare(
            'UPDATE document_locks
             SET expires_at = :exp
             WHERE entity_type = :type AND entity_id = :id
               AND user_id = :uid AND expires_at > :now'
        );
        $stmt->execute([':exp' => $expiresAt, ':type' => $entityType, ':id' => $entityId, ':uid' => $userId, ':now' => $now]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Release the lock. Only the current holder can release.
     *
     * @return bool True if the lock was released.
     */
    public function release(string $entityType, string $entityId, string $userId): bool
    {
        [$entityType, $entityId, $userId] = $this->validateInputs($entityType, $entityId, $userId);
        $stmt = $this->db()->prepare(
            'DELETE FROM document_locks
             WHERE entity_type = :type AND entity_id = :id AND user_id = :uid'
        );
        $stmt->execute([':type' => $entityType, ':id' => $entityId, ':uid' => $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Check whether the document is currently locked by an active lock.
     */
    public function isLocked(string $entityType, string $entityId): bool
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM document_locks
             WHERE entity_type = :type AND entity_id = :id AND expires_at > :now'
        );
        $stmt->execute([':type' => trim($entityType), ':id' => trim($entityId), ':now' => $now]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Get the current lock holder record (null if not locked or expired).
     *
     * @return array<string,mixed>|null
     */
    public function holder(string $entityType, string $entityId): ?array
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'SELECT id, entity_type, entity_id, user_id, expires_at, acquired_at
             FROM document_locks
             WHERE entity_type = :type AND entity_id = :id AND expires_at > :now
             LIMIT 1'
        );
        $stmt->execute([':type' => trim($entityType), ':id' => trim($entityId), ':now' => $now]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Delete all expired locks (maintenance).
     *
     * @return int Number of rows deleted.
     */
    public function releaseExpired(): int
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare('DELETE FROM document_locks WHERE expires_at <= :now');
        $stmt->execute([':now' => $now]);
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
    private function validateInputs(string $entityType, string $entityId, string $userId): array
    {
        $entityType = trim($entityType);
        $entityId   = trim($entityId);
        $userId     = trim($userId);
        if ($entityType === '') {
            throw new \InvalidArgumentException('entity_type must not be empty.');
        }
        if ($entityId === '') {
            throw new \InvalidArgumentException('entity_id must not be empty.');
        }
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }
        return [$entityType, $entityId, $userId];
    }
}
