<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * TimeEntry — work time tracking with start/stop/duration.
 *
 * Tracks time entries per user and optional project/task. Supports
 * manual entries (with explicit start and end times) and active timers
 * (started but not yet stopped). Duration is always stored in seconds.
 *
 * ## Usage
 *
 * ```php
 * $te = new TimeEntry($pdo);
 *
 * // Start a timer
 * $id = $te->start('user-1', 'Fixing login bug', 'project-42');
 * // ... work ...
 * $te->stop($id);
 *
 * // Manual entry
 * $te->add('user-1', '2026-05-27 09:00:00', '2026-05-27 11:30:00', 'Design review');
 *
 * $total = $te->totalSeconds('user-1');
 * $log   = $te->forUser('user-1');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE time_entries (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     user_id     VARCHAR(255) NOT NULL,
 *     project_ref TEXT         NULL,
 *     description TEXT         NULL,
 *     started_at  DATETIME     NOT NULL,
 *     stopped_at  DATETIME     NULL,
 *     seconds     INTEGER      NULL,
 *     created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class TimeEntry
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Start an active timer for a user.
     *
     * @return int Entry ID.
     * @throws \InvalidArgumentException on empty user_id.
     */
    public function start(string $userId, ?string $description = null, ?string $projectRef = null): int
    {
        $userId = trim($userId);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'INSERT INTO time_entries (user_id, project_ref, description, started_at, created_at)
             VALUES (:uid, :proj, :desc, :now, :now)'
        );
        $stmt->execute([
            ':uid'  => $userId,
            ':proj' => $projectRef,
            ':desc' => $description,
            ':now'  => $now,
        ]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Stop an active timer. Calculates and stores the duration in seconds.
     *
     * @return bool True if found (and was active) and stopped.
     */
    public function stop(int $id): bool
    {
        $row = $this->find($id);
        if ($row === null || $row['stopped_at'] !== null) {
            return false;
        }
        $now     = new \DateTimeImmutable();
        $started = new \DateTimeImmutable((string)$row['started_at']);
        $seconds = $now->getTimestamp() - $started->getTimestamp();
        $seconds = max(0, $seconds);

        $stmt = $this->db()->prepare(
            'UPDATE time_entries SET stopped_at = :now, seconds = :sec WHERE id = :id AND stopped_at IS NULL'
        );
        $stmt->execute([':now' => $now->format('Y-m-d H:i:s'), ':sec' => $seconds, ':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Add a manual time entry with explicit start and end.
     *
     * @return int Entry ID.
     * @throws \InvalidArgumentException on empty user_id or if end is before start.
     */
    public function add(
        string $userId,
        string $startedAt,
        string $stoppedAt,
        ?string $description = null,
        ?string $projectRef = null
    ): int {
        $userId = trim($userId);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }
        $start   = new \DateTimeImmutable($startedAt);
        $stop    = new \DateTimeImmutable($stoppedAt);
        $seconds = $stop->getTimestamp() - $start->getTimestamp();
        if ($seconds < 0) {
            throw new \InvalidArgumentException('stopped_at must not be before started_at.');
        }
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'INSERT INTO time_entries (user_id, project_ref, description, started_at, stopped_at, seconds, created_at)
             VALUES (:uid, :proj, :desc, :start, :stop, :sec, :now)'
        );
        $stmt->execute([
            ':uid'   => $userId,
            ':proj'  => $projectRef,
            ':desc'  => $description,
            ':start' => $start->format('Y-m-d H:i:s'),
            ':stop'  => $stop->format('Y-m-d H:i:s'),
            ':sec'   => $seconds,
            ':now'   => $now,
        ]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Find a time entry by ID.
     *
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, user_id, project_ref, description, started_at, stopped_at, seconds, created_at
             FROM time_entries WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Delete a time entry.
     *
     * @return bool True if found and deleted.
     */
    public function delete(int $id): bool
    {
        $stmt = $this->db()->prepare('DELETE FROM time_entries WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Return all time entries for a user, newest first.
     *
     * @return list<array<string,mixed>>
     */
    public function forUser(string $userId, ?string $projectRef = null): array
    {
        $sql    = 'SELECT id, user_id, project_ref, description, started_at, stopped_at, seconds, created_at
                   FROM time_entries WHERE user_id = :uid';
        $params = [':uid' => trim($userId)];
        if ($projectRef !== null) {
            $sql             .= ' AND project_ref = :proj';
            $params[':proj']  = $projectRef;
        }
        $sql .= ' ORDER BY id DESC';
        $stmt = $this->db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Return total logged seconds for a user (completed entries only).
     */
    public function totalSeconds(string $userId, ?string $projectRef = null): int
    {
        $sql    = 'SELECT COALESCE(SUM(seconds), 0) FROM time_entries
                   WHERE user_id = :uid AND stopped_at IS NOT NULL';
        $params = [':uid' => trim($userId)];
        if ($projectRef !== null) {
            $sql             .= ' AND project_ref = :proj';
            $params[':proj']  = $projectRef;
        }
        $stmt = $this->db()->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Return the currently active (unstopped) timer for a user, or null.
     *
     * @return array<string,mixed>|null
     */
    public function activeTimer(string $userId): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, user_id, project_ref, description, started_at, stopped_at, seconds, created_at
             FROM time_entries
             WHERE user_id = :uid AND stopped_at IS NULL
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':uid' => trim($userId)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    // ── internal helpers ──────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
