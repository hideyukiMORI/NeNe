<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * PresenceChannel — track which users are currently "in" a named channel.
 *
 * Suitable for chat rooms, collaborative documents, live sessions, or any feature
 * where you need to know who is currently present. Presence is heartbeat-based;
 * stale entries expire automatically via purgeStale().
 *
 * ## Usage
 *
 * ```php
 * $pc = new PresenceChannel($pdo, ttlSeconds: 60);
 *
 * // User joins
 * $pc->join('room-1', 'user-1');
 *
 * // Send heartbeat (refresh TTL)
 * $pc->heartbeat('room-1', 'user-1');
 *
 * // Who's in the channel?
 * $pc->members('room-1'); // ['user-1', 'user-2']
 *
 * // User leaves explicitly
 * $pc->leave('room-1', 'user-1');
 *
 * // Purge expired members across all channels
 * $pc->purgeStale();
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE presence_channel (
 *     channel_name VARCHAR(255) NOT NULL,
 *     user_id      VARCHAR(255) NOT NULL,
 *     joined_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     last_seen    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     PRIMARY KEY (channel_name, user_id)
 * );
 * ```
 */
final class PresenceChannel
{
    public function __construct(
        private readonly ?PDO $db = null,
        private readonly int $ttlSeconds = 60,
    ) {
        if ($this->ttlSeconds <= 0) {
            throw new \InvalidArgumentException('ttlSeconds must be positive.');
        }
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Join a channel (or refresh presence if already in it).
     *
     * @throws \InvalidArgumentException if channel_name or user_id is empty.
     */
    public function join(string $channelName, string $userId): void
    {
        [$channelName, $userId] = $this->normalise($channelName, $userId);
        $db     = $this->db();
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $db->prepare(
                'INSERT INTO presence_channel (channel_name, user_id)
                 VALUES (:ch, :uid)
                 ON CONFLICT (channel_name, user_id)
                 DO UPDATE SET last_seen = CURRENT_TIMESTAMP'
            )->execute([':ch' => $channelName, ':uid' => $userId]);
        } else {
            $db->prepare(
                'INSERT INTO presence_channel (channel_name, user_id)
                 VALUES (:ch, :uid)
                 ON DUPLICATE KEY UPDATE last_seen = CURRENT_TIMESTAMP'
            )->execute([':ch' => $channelName, ':uid' => $userId]);
        }
    }

    /**
     * Refresh a user's presence TTL (keep them "alive" in the channel).
     *
     * If the user is not in the channel, this is a no-op (returns false).
     *
     * @return bool True if the user was found and refreshed.
     */
    public function heartbeat(string $channelName, string $userId): bool
    {
        [$channelName, $userId] = $this->normalise($channelName, $userId);
        $stmt = $this->db()->prepare(
            'UPDATE presence_channel
             SET last_seen = CURRENT_TIMESTAMP
             WHERE channel_name = :ch AND user_id = :uid'
        );
        $stmt->execute([':ch' => $channelName, ':uid' => $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Explicitly remove a user from a channel.
     *
     * @return bool True if the user was in the channel.
     */
    public function leave(string $channelName, string $userId): bool
    {
        [$channelName, $userId] = $this->normalise($channelName, $userId);
        $stmt = $this->db()->prepare(
            'DELETE FROM presence_channel WHERE channel_name = :ch AND user_id = :uid'
        );
        $stmt->execute([':ch' => $channelName, ':uid' => $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * List all non-stale members of a channel.
     *
     * @return list<string>  User IDs currently present.
     */
    public function members(string $channelName): array
    {
        $channelName = trim($channelName);
        $cutoff = $this->cutoff();
        $stmt   = $this->db()->prepare(
            'SELECT user_id FROM presence_channel
             WHERE channel_name = :ch AND last_seen >= :cutoff
             ORDER BY joined_at ASC'
        );
        $stmt->execute([':ch' => $channelName, ':cutoff' => $cutoff]);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], 'user_id');
    }

    /**
     * Check whether a user is currently in a channel (non-stale).
     */
    public function isPresent(string $channelName, string $userId): bool
    {
        [$channelName, $userId] = $this->normalise($channelName, $userId);
        $cutoff = $this->cutoff();
        $stmt   = $this->db()->prepare(
            'SELECT COUNT(*) FROM presence_channel
             WHERE channel_name = :ch AND user_id = :uid AND last_seen >= :cutoff'
        );
        $stmt->execute([':ch' => $channelName, ':uid' => $userId, ':cutoff' => $cutoff]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Count non-stale members in a channel.
     */
    public function count(string $channelName): int
    {
        $channelName = trim($channelName);
        $cutoff = $this->cutoff();
        $stmt   = $this->db()->prepare(
            'SELECT COUNT(*) FROM presence_channel
             WHERE channel_name = :ch AND last_seen >= :cutoff'
        );
        $stmt->execute([':ch' => $channelName, ':cutoff' => $cutoff]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * List all channels a user is currently present in.
     *
     * @return list<string>  Channel names.
     */
    public function channelsForUser(string $userId): array
    {
        $userId = trim($userId);
        $cutoff = $this->cutoff();
        $stmt   = $this->db()->prepare(
            'SELECT channel_name FROM presence_channel
             WHERE user_id = :uid AND last_seen >= :cutoff
             ORDER BY channel_name ASC'
        );
        $stmt->execute([':uid' => $userId, ':cutoff' => $cutoff]);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], 'channel_name');
    }

    /**
     * Remove stale (timed-out) presence entries across all channels.
     *
     * @return int Number of rows deleted.
     */
    public function purgeStale(): int
    {
        $cutoff = $this->cutoff();
        $stmt   = $this->db()->prepare(
            'DELETE FROM presence_channel WHERE last_seen < :cutoff'
        );
        $stmt->execute([':cutoff' => $cutoff]);
        return $stmt->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    private function cutoff(): string
    {
        return (new \DateTimeImmutable())->modify("-{$this->ttlSeconds} seconds")->format('Y-m-d H:i:s');
    }

    /**
     * @return array{string, string}
     */
    private function normalise(string $channelName, string $userId): array
    {
        $channelName = trim($channelName);
        $userId      = trim($userId);
        if ($channelName === '') {
            throw new \InvalidArgumentException('channel_name must not be empty.');
        }
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }
        return [$channelName, $userId];
    }
}
