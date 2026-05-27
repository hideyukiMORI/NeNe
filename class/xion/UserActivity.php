<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * UserActivity — user last-seen and named action tracking.
 *
 * Records when a user was last seen and logs discrete named activity events.
 * Useful for "online/recently active" indicators, usage analytics, and
 * inactivity-based cleanup.
 *
 * ## Usage
 *
 * ```php
 * $ua = new UserActivity($pdo);
 *
 * // Touch on every authenticated request
 * $ua->touch('user-1');
 *
 * // Log a named action
 * $ua->log('user-1', 'viewed_dashboard');
 * $ua->log('user-1', 'export_requested', ['format' => 'csv']);
 *
 * // Query
 * $ua->lastSeen('user-1');               // ?DateTimeImmutable
 * $ua->isRecentlyActive('user-1', 300);  // seen in last 5 min?
 * $ua->recentActions('user-1', 10);      // last 10 actions
 * $ua->countActions('user-1', 'viewed_dashboard'); // total count
 * $ua->purgeOlderThan(90);              // maintenance
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE user_last_seen (
 *     user_id    VARCHAR(255) NOT NULL PRIMARY KEY,
 *     last_seen  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 *
 * CREATE TABLE user_activity_log (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     user_id    VARCHAR(255) NOT NULL,
 *     action     VARCHAR(100) NOT NULL,
 *     meta       TEXT         NOT NULL DEFAULT '',
 *     logged_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class UserActivity
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Update the user's last-seen timestamp to now.
     *
     * Creates or updates the row idempotently.
     *
     * @throws \InvalidArgumentException if user_id is empty.
     */
    public function touch(string $userId): void
    {
        $userId = $this->validateUserId($userId);
        $now    = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $db     = $this->db();

        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $db->prepare(
                'INSERT INTO user_last_seen (user_id, last_seen)
                 VALUES (:uid, :now)
                 ON CONFLICT (user_id) DO UPDATE SET last_seen = excluded.last_seen'
            )->execute([':uid' => $userId, ':now' => $now]);
        } else {
            $db->prepare(
                'INSERT INTO user_last_seen (user_id, last_seen)
                 VALUES (:uid, :now)
                 ON DUPLICATE KEY UPDATE last_seen = VALUES(last_seen)'
            )->execute([':uid' => $userId, ':now' => $now]);
        }
    }

    /**
     * Get the user's last-seen timestamp, or null if never seen.
     */
    public function lastSeen(string $userId): ?\DateTimeImmutable
    {
        $userId = $this->validateUserId($userId);
        $stmt   = $this->db()->prepare(
            'SELECT last_seen FROM user_last_seen WHERE user_id = :uid LIMIT 1'
        );
        $stmt->execute([':uid' => $userId]);
        $val = $stmt->fetchColumn();
        if ($val === false) {
            return null;
        }
        return new \DateTimeImmutable((string)$val);
    }

    /**
     * Return true if the user was last seen within $withinSeconds seconds.
     */
    public function isRecentlyActive(string $userId, int $withinSeconds = 300): bool
    {
        $ts = $this->lastSeen($userId);
        if ($ts === null) {
            return false;
        }
        $diff = (new \DateTimeImmutable())->getTimestamp() - $ts->getTimestamp();
        return $diff <= $withinSeconds;
    }

    /**
     * Log a named activity event for a user.
     *
     * @param  array<string,mixed> $meta  Optional structured metadata (JSON-encoded).
     * @return int  The new log entry ID.
     * @throws \InvalidArgumentException if user_id or action is empty.
     */
    public function log(string $userId, string $action, array $meta = []): int
    {
        $userId = $this->validateUserId($userId);
        $action = trim($action);
        if ($action === '') {
            throw new \InvalidArgumentException('action must not be empty.');
        }
        $stmt = $this->db()->prepare(
            'INSERT INTO user_activity_log (user_id, action, meta)
             VALUES (:uid, :action, :meta)'
        );
        $stmt->execute([
            ':uid'    => $userId,
            ':action' => $action,
            ':meta'   => $meta !== [] ? json_encode($meta, JSON_UNESCAPED_UNICODE) : '',
        ]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Get the N most recent activity log entries for a user.
     *
     * @return list<array<string,mixed>>
     */
    public function recentActions(string $userId, int $limit = 20): array
    {
        $userId = $this->validateUserId($userId);
        $limit  = max(1, $limit);
        $stmt   = $this->db()->prepare(
            "SELECT id, user_id, action, meta, logged_at
             FROM user_activity_log
             WHERE user_id = :uid
             ORDER BY id DESC
             LIMIT {$limit}"
        );
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Count activity log entries for a user, optionally filtered by action name.
     */
    public function countActions(string $userId, ?string $action = null): int
    {
        $userId = $this->validateUserId($userId);
        if ($action !== null) {
            $stmt = $this->db()->prepare(
                'SELECT COUNT(*) FROM user_activity_log WHERE user_id = :uid AND action = :action'
            );
            $stmt->execute([':uid' => $userId, ':action' => $action]);
        } else {
            $stmt = $this->db()->prepare(
                'SELECT COUNT(*) FROM user_activity_log WHERE user_id = :uid'
            );
            $stmt->execute([':uid' => $userId]);
        }
        return (int)$stmt->fetchColumn();
    }

    /**
     * Purge activity log entries older than N days.
     *
     * @return int Number of rows deleted.
     */
    public function purgeOlderThan(int $days): int
    {
        $cutoff = (new \DateTimeImmutable())->modify("-{$days} days")->format('Y-m-d H:i:s');
        $stmt   = $this->db()->prepare(
            'DELETE FROM user_activity_log WHERE logged_at < :cutoff'
        );
        $stmt->execute([':cutoff' => $cutoff]);
        return $stmt->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    private function validateUserId(string $userId): string
    {
        $userId = trim($userId);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }
        return $userId;
    }
}
