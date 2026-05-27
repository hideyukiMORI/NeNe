<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * PresenceTracker — user online presence and last-seen tracking.
 *
 * Records when a user was last active so the application can show
 * "online", "recently active", or "offline" indicators. A heartbeat
 * call updates the last-seen timestamp; presence is derived by comparing
 * that timestamp against a configurable "online window" in seconds.
 *
 * Per-room or per-channel presence is supported through an optional
 * $context string.
 *
 * ## Usage
 *
 * ```php
 * $pt = new PresenceTracker($pdo);
 *
 * // User activity (call from each page load or heartbeat endpoint)
 * $pt->ping('user-1');
 *
 * // Who's online right now? (active in the last 5 minutes)
 * $online = $pt->online(300);
 *
 * // Is a specific user online?
 * if ($pt->isOnline('user-1', 300)) { ... }
 *
 * // Channel presence
 * $pt->ping('user-1', 'room:lobby');
 * $inRoom = $pt->onlineIn('room:lobby', 300);
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE presence_tracker (
 *     id           INTEGER PRIMARY KEY AUTOINCREMENT,
 *     user_id      VARCHAR(255) NOT NULL,
 *     context      VARCHAR(255) NOT NULL DEFAULT '',
 *     last_seen_at DATETIME     NOT NULL,
 *     created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (user_id, context)
 * );
 * ```
 */
final class PresenceTracker
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Record that a user was just seen (heartbeat).
     *
     * Creates the row on first call; updates last_seen_at on subsequent calls.
     *
     * @throws \InvalidArgumentException on empty userId.
     */
    public function ping(string $userId, string $context = ''): void
    {
        $userId = trim($userId);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }

        $now    = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $driver = $this->db()->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $this->db()->prepare(
                'INSERT INTO presence_tracker (user_id, context, last_seen_at, created_at)
                 VALUES (:uid, :ctx, :now, :now2)
                 ON CONFLICT (user_id, context) DO UPDATE SET
                     last_seen_at = excluded.last_seen_at'
            );
        } else {
            $stmt = $this->db()->prepare(
                'INSERT INTO presence_tracker (user_id, context, last_seen_at, created_at)
                 VALUES (:uid, :ctx, :now, :now2)
                 ON DUPLICATE KEY UPDATE
                     last_seen_at = VALUES(last_seen_at)'
            );
        }
        $stmt->execute([':uid' => $userId, ':ctx' => $context, ':now' => $now, ':now2' => $now]);
    }

    /**
     * Return the last-seen record for a user (and optional context).
     *
     * @return array<string,mixed>|null
     */
    public function lastSeen(string $userId, string $context = ''): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM presence_tracker WHERE user_id = :uid AND context = :ctx'
        );
        $stmt->execute([':uid' => trim($userId), ':ctx' => $context]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Return true if the user was seen within $windowSeconds.
     *
     * @param int $windowSeconds Seconds to consider "online" (default 300 = 5 min).
     */
    public function isOnline(string $userId, int $windowSeconds = 300, string $context = ''): bool
    {
        $cutoff = (new \DateTimeImmutable())->modify("-{$windowSeconds} seconds")->format('Y-m-d H:i:s');
        $stmt   = $this->db()->prepare(
            'SELECT COUNT(*) FROM presence_tracker
             WHERE user_id = :uid AND context = :ctx AND last_seen_at >= :cutoff'
        );
        $stmt->execute([':uid' => trim($userId), ':ctx' => $context, ':cutoff' => $cutoff]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Return all users seen within $windowSeconds (globally or in a context).
     *
     * @return list<array<string,mixed>>
     */
    public function online(int $windowSeconds = 300, string $context = ''): array
    {
        $cutoff = (new \DateTimeImmutable())->modify("-{$windowSeconds} seconds")->format('Y-m-d H:i:s');
        $stmt   = $this->db()->prepare(
            'SELECT * FROM presence_tracker
             WHERE context = :ctx AND last_seen_at >= :cutoff
             ORDER BY last_seen_at DESC, id DESC'
        );
        $stmt->execute([':ctx' => $context, ':cutoff' => $cutoff]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Return all users online in a specific context within $windowSeconds.
     *
     * Alias for online() with a non-empty context.
     *
     * @return list<array<string,mixed>>
     */
    public function onlineIn(string $context, int $windowSeconds = 300): array
    {
        return $this->online($windowSeconds, $context);
    }

    /**
     * Count users seen within $windowSeconds in a context.
     */
    public function countOnline(int $windowSeconds = 300, string $context = ''): int
    {
        $cutoff = (new \DateTimeImmutable())->modify("-{$windowSeconds} seconds")->format('Y-m-d H:i:s');
        $stmt   = $this->db()->prepare(
            'SELECT COUNT(*) FROM presence_tracker
             WHERE context = :ctx AND last_seen_at >= :cutoff'
        );
        $stmt->execute([':ctx' => $context, ':cutoff' => $cutoff]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Remove a user's presence record for a context (e.g. explicit "leave room").
     *
     * @return bool True if found and removed.
     */
    public function leave(string $userId, string $context = ''): bool
    {
        $stmt = $this->db()->prepare(
            'DELETE FROM presence_tracker WHERE user_id = :uid AND context = :ctx'
        );
        $stmt->execute([':uid' => trim($userId), ':ctx' => $context]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Remove all presence records for a user (across all contexts).
     *
     * @return int Number of rows deleted.
     */
    public function removeUser(string $userId): int
    {
        $stmt = $this->db()->prepare('DELETE FROM presence_tracker WHERE user_id = :uid');
        $stmt->execute([':uid' => trim($userId)]);
        return $stmt->rowCount();
    }

    /**
     * Delete presence records not updated since $cutoff.
     *
     * @return int Number of rows deleted.
     */
    public function purgeOlderThan(string $cutoff): int
    {
        $stmt = $this->db()->prepare('DELETE FROM presence_tracker WHERE last_seen_at < :cutoff');
        $stmt->execute([':cutoff' => $cutoff]);
        return $stmt->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
