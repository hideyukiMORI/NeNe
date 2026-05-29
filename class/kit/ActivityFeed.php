<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * User-facing activity feed — append-only event timeline.
 *
 * Records structured events for a user (e.g. "liked a post", "earned a badge").
 * Each event has a `type`, optional `subject_type`/`subject_id` target, and a
 * JSON-serialisable `data` payload. Events are never updated or deleted by design
 * (append-only audit pattern); only an explicit purge clears old entries.
 *
 * ## Usage
 *
 * ```php
 * $feed = new ActivityFeed($pdo);
 *
 * // Record an event
 * $feed->record('user-1', 'liked_post', 'post', '42', ['title' => 'Hello World']);
 *
 * // Fetch latest events
 * $events = $feed->fetch('user-1', limit: 20);
 *
 * // Count
 * $feed->count('user-1');
 *
 * // Purge old entries (e.g. keep last 90 days)
 * $feed->purgeOlderThan('user-1', days: 90);
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE activity_feed (
 *     id           INTEGER PRIMARY KEY AUTOINCREMENT,
 *     user_id      VARCHAR(255) NOT NULL,
 *     type         VARCHAR(100) NOT NULL,
 *     subject_type VARCHAR(100) NOT NULL DEFAULT '',
 *     subject_id   VARCHAR(255) NOT NULL DEFAULT '',
 *     data         TEXT         NOT NULL DEFAULT '{}',
 *     created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class ActivityFeed
{
    private const MAX_LIMIT = 100;

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Record an activity event for a user.
     *
     * @param string               $userId      Actor / owner of the event.
     * @param string               $type        Event type slug (e.g. 'liked_post', 'earned_badge').
     * @param string               $subjectType Optional subject entity type.
     * @param string               $subjectId   Optional subject entity ID.
     * @param array<string, mixed> $data        Optional JSON payload.
     * @return int                 New event ID.
     * @throws \InvalidArgumentException if type is empty.
     */
    public function record(
        string $userId,
        string $type,
        string $subjectType = '',
        string $subjectId = '',
        array $data = []
    ): int {
        $type = trim($type);
        if ($type === '') {
            throw new \InvalidArgumentException('Activity type must not be empty.');
        }

        $stmt = $this->db()->prepare(
            'INSERT INTO activity_feed (user_id, type, subject_type, subject_id, data)
             VALUES (:user, :type, :subject_type, :subject_id, :data)'
        );
        $stmt->execute([
            ':user'         => $userId,
            ':type'         => $type,
            ':subject_type' => trim($subjectType),
            ':subject_id'   => trim($subjectId),
            ':data'         => json_encode($data, JSON_UNESCAPED_UNICODE),
        ]);

        return (int)$this->db()->lastInsertId();
    }

    /**
     * Fetch recent activity events for a user (newest first).
     *
     * @param  int         $limit      Max events to return (1–100; default 20).
     * @param  string|null $type       Optional filter by event type.
     * @param  int|null    $beforeId   Optional cursor — fetch events with id < beforeId.
     * @return list<array<string, mixed>>
     */
    public function fetch(string $userId, int $limit = 20, ?string $type = null, ?int $beforeId = null): array
    {
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        $clauses = ['user_id = :user'];
        $params  = [':user' => $userId];

        if ($type !== null) {
            $clauses[] = 'type = :type';
            $params[':type'] = $type;
        }

        if ($beforeId !== null) {
            $clauses[] = 'id < :before';
            $params[':before'] = $beforeId;
        }

        $where = implode(' AND ', $clauses);
        $stmt  = $this->db()->prepare(
            "SELECT * FROM activity_feed WHERE {$where} ORDER BY id DESC LIMIT {$limit}"
        );
        $stmt->execute($params);

        return array_map(
            static function (array $row): array {
                $row['data'] = json_decode($row['data'], true) ?? [];
                return $row;
            },
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /**
     * Count all events for a user (or filtered by type).
     */
    public function count(string $userId, ?string $type = null): int
    {
        if ($type !== null) {
            $stmt = $this->db()->prepare(
                'SELECT COUNT(*) FROM activity_feed WHERE user_id = :user AND type = :type'
            );
            $stmt->execute([':user' => $userId, ':type' => $type]);
        } else {
            $stmt = $this->db()->prepare('SELECT COUNT(*) FROM activity_feed WHERE user_id = :user');
            $stmt->execute([':user' => $userId]);
        }
        return (int)$stmt->fetchColumn();
    }

    /**
     * Purge events older than `$days` days for a user.
     *
     * @return int Number of rows deleted.
     */
    public function purgeOlderThan(string $userId, int $days): int
    {
        if ($days <= 0) {
            throw new \InvalidArgumentException("Days must be positive, got {$days}.");
        }

        $cutoff = date('Y-m-d H:i:s', time() - $days * 86400);
        $stmt   = $this->db()->prepare(
            'DELETE FROM activity_feed WHERE user_id = :user AND created_at < :cutoff'
        );
        $stmt->execute([':user' => $userId, ':cutoff' => $cutoff]);
        return $stmt->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
