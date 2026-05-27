<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * DailyDigest — per-user daily digest item accumulator.
 *
 * Items are queued throughout the day with a category and content.
 * A background job collects all unsent items for each user, marks them
 * as sent, and delivers a summary (email, push, etc.). The delivery
 * mechanism is outside scope.
 *
 * ## Usage
 *
 * ```php
 * $dd = new DailyDigest($pdo);
 *
 * // Queue items during the day
 * $dd->add('user-1', 'new_comment', 'User X commented on your post.');
 * $dd->add('user-1', 'new_follower', 'User Y followed you.');
 *
 * // Cron job: collect and mark as sent
 * $pending = $dd->pendingFor('user-1');
 * // ... deliver digest email ...
 * $dd->markSent('user-1', array_column($pending, 'id'));
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE daily_digest_items (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     user_id    VARCHAR(255) NOT NULL,
 *     category   VARCHAR(100) NOT NULL DEFAULT '',
 *     content    TEXT         NOT NULL,
 *     sent       TINYINT(1)   NOT NULL DEFAULT 0,
 *     sent_at    DATETIME     NULL,
 *     created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class DailyDigest
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Add an item to a user's digest queue.
     *
     * @return int Row ID.
     * @throws \InvalidArgumentException on empty fields.
     */
    public function add(string $userId, string $category, string $content): int
    {
        $userId  = trim($userId);
        $content = trim($content);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }
        if ($content === '') {
            throw new \InvalidArgumentException('content must not be empty.');
        }

        $stmt = $this->db()->prepare(
            'INSERT INTO daily_digest_items (user_id, category, content)
             VALUES (:uid, :cat, :content)'
        );
        $stmt->execute([
            ':uid'     => $userId,
            ':cat'     => trim($category),
            ':content' => $content,
        ]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Return all unsent items for a user (oldest first).
     *
     * @return list<array<string,mixed>>
     */
    public function pendingFor(string $userId): array
    {
        $userId = trim($userId);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }
        $stmt = $this->db()->prepare(
            'SELECT id, user_id, category, content, sent, sent_at, created_at
             FROM daily_digest_items
             WHERE user_id = :uid AND sent = 0
             ORDER BY id ASC'
        );
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Return all unsent items grouped by user (for batch delivery).
     *
     * @return array<string, list<array<string,mixed>>>  user_id → items
     */
    public function allPending(): array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, user_id, category, content, sent, sent_at, created_at
             FROM daily_digest_items
             WHERE sent = 0
             ORDER BY user_id ASC, id ASC'
        );
        $stmt->execute();
        $rows   = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $result = [];
        foreach ($rows as $row) {
            $uid            = (string)$row['user_id'];
            $result[$uid][] = $row;
        }
        return $result;
    }

    /**
     * Mark a list of item IDs as sent for a user.
     *
     * Only marks rows that belong to the given user.
     *
     * @param  list<int> $ids
     * @return int Number of rows updated.
     */
    public function markSent(string $userId, array $ids): int
    {
        $userId = trim($userId);
        if ($userId === '' || $ids === []) {
            return 0;
        }
        $now          = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt         = $this->db()->prepare(
            "UPDATE daily_digest_items
             SET sent = 1, sent_at = ?
             WHERE user_id = ? AND sent = 0 AND id IN ({$placeholders})"
        );
        $stmt->execute(array_merge([$now, $userId], array_values($ids)));
        return $stmt->rowCount();
    }

    /**
     * Count unsent items for a user.
     */
    public function pendingCount(string $userId): int
    {
        $userId = trim($userId);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM daily_digest_items WHERE user_id = :uid AND sent = 0'
        );
        $stmt->execute([':uid' => $userId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Delete sent items older than a given number of days (maintenance).
     *
     * @return int Number of rows deleted.
     */
    public function purgeSent(int $days): int
    {
        $cutoff = (new \DateTimeImmutable())->modify("-{$days} days")->format('Y-m-d H:i:s');
        $stmt   = $this->db()->prepare(
            'DELETE FROM daily_digest_items WHERE sent = 1 AND sent_at <= :cutoff'
        );
        $stmt->execute([':cutoff' => $cutoff]);
        return $stmt->rowCount();
    }

    /**
     * Delete all digest items for a user (e.g. on account deletion).
     *
     * @return int Number of rows deleted.
     */
    public function deleteAll(string $userId): int
    {
        $userId = trim($userId);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }
        $stmt = $this->db()->prepare('DELETE FROM daily_digest_items WHERE user_id = :uid');
        $stmt->execute([':uid' => $userId]);
        return $stmt->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
