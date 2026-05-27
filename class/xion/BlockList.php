<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * User block list — prevent harassment and unwanted interactions.
 *
 * A block is a one-way relationship: blocker → blocked.
 * Both sides can be queried so callers can implement bidirectional hiding.
 *
 * ## Usage
 *
 * ```php
 * $bl = new BlockList($pdo);
 *
 * // Block
 * $bl->block('alice', 'bob');
 *
 * // Check
 * $bl->isBlocked('alice', 'bob');  // true (alice blocked bob)
 * $bl->isBlockedBy('bob', 'alice'); // true (bob is blocked by alice)
 * $bl->isEitherBlocked('alice', 'bob'); // true (either direction)
 *
 * // Unblock
 * $bl->unblock('alice', 'bob');
 *
 * // List
 * $bl->blockedBy('alice');  // IDs alice blocked
 * $bl->blockers('bob');     // IDs who blocked bob
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE block_list (
 *     blocker_id  VARCHAR(255) NOT NULL,
 *     blocked_id  VARCHAR(255) NOT NULL,
 *     created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     PRIMARY KEY (blocker_id, blocked_id)
 * );
 * ```
 */
final class BlockList
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Block a user.
     *
     * Idempotent: blocking an already-blocked user is a no-op.
     *
     * @throws \InvalidArgumentException if blocker and blocked are the same user.
     */
    public function block(string $blockerId, string $blockedId): void
    {
        if ($blockerId === $blockedId) {
            throw new \InvalidArgumentException('A user cannot block themselves.');
        }

        $db     = $this->db();
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql    = $driver === 'sqlite'
            ? 'INSERT OR IGNORE INTO block_list (blocker_id, blocked_id) VALUES (:blocker, :blocked)'
            : 'INSERT IGNORE INTO block_list (blocker_id, blocked_id) VALUES (:blocker, :blocked)';

        $stmt = $db->prepare($sql);
        $stmt->execute([':blocker' => $blockerId, ':blocked' => $blockedId]);
    }

    /**
     * Unblock a user.
     *
     * @return bool True if the block existed and was removed.
     */
    public function unblock(string $blockerId, string $blockedId): bool
    {
        $stmt = $this->db()->prepare(
            'DELETE FROM block_list WHERE blocker_id = :blocker AND blocked_id = :blocked'
        );
        $stmt->execute([':blocker' => $blockerId, ':blocked' => $blockedId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Check if `$blockerId` has blocked `$blockedId`.
     */
    public function isBlocked(string $blockerId, string $blockedId): bool
    {
        $stmt = $this->db()->prepare(
            'SELECT 1 FROM block_list WHERE blocker_id = :blocker AND blocked_id = :blocked LIMIT 1'
        );
        $stmt->execute([':blocker' => $blockerId, ':blocked' => $blockedId]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Check if `$userId` has been blocked by `$byUserId` (reverse direction).
     */
    public function isBlockedBy(string $userId, string $byUserId): bool
    {
        return $this->isBlocked($byUserId, $userId);
    }

    /**
     * Check if either user has blocked the other.
     *
     * Useful for hiding content in both directions without two calls.
     */
    public function isEitherBlocked(string $userA, string $userB): bool
    {
        $stmt = $this->db()->prepare(
            'SELECT 1 FROM block_list
             WHERE (blocker_id = :a AND blocked_id = :b)
                OR (blocker_id = :b2 AND blocked_id = :a2)
             LIMIT 1'
        );
        $stmt->execute([':a' => $userA, ':b' => $userB, ':a2' => $userA, ':b2' => $userB]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Return the list of user IDs that `$blockerId` has blocked.
     *
     * @return list<string>
     */
    public function blockedBy(string $blockerId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT blocked_id FROM block_list WHERE blocker_id = :blocker ORDER BY created_at ASC'
        );
        $stmt->execute([':blocker' => $blockerId]);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'blocked_id');
    }

    /**
     * Return the list of user IDs who have blocked `$userId`.
     *
     * @return list<string>
     */
    public function blockers(string $userId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT blocker_id FROM block_list WHERE blocked_id = :blocked ORDER BY created_at ASC'
        );
        $stmt->execute([':blocked' => $userId]);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'blocker_id');
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
