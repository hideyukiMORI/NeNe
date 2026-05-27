<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * Track per-user daily activity streaks.
 *
 * A streak increments when a user checks in on a calendar day immediately
 * following the previous check-in day. Any gap larger than one day resets the
 * current streak to 1.  Multiple check-ins on the same day are idempotent.
 *
 * ## Usage
 *
 * ```php
 * $ds = new DailyStreak($pdo);
 *
 * // User performs their daily action
 * $ds->checkin('user-1');
 *
 * // Query streaks
 * $ds->currentStreak('user-1'); // e.g. 5
 * $ds->longestStreak('user-1'); // e.g. 12
 * $ds->lastCheckedIn('user-1'); // e.g. '2026-05-28'
 *
 * // Admin reset
 * $ds->reset('user-1');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE daily_streaks (
 *     user_id           VARCHAR(255) PRIMARY KEY,
 *     current_streak    INT          NOT NULL DEFAULT 0,
 *     longest_streak    INT          NOT NULL DEFAULT 0,
 *     last_checked_in   DATE         NULL,
 *     created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class DailyStreak
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── private helpers ───────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    // ── checkin ───────────────────────────────────────────────────────────────

    /**
     * Record a check-in for today.
     *
     * Returns true if the streak was updated (or created), false if the user
     * has already checked in today (idempotent duplicate).
     */
    public function checkin(string $userId, ?string $todayDate = null): bool
    {
        if ($userId === '') {
            throw new \InvalidArgumentException('userId must not be empty.');
        }

        $today = $todayDate ?? date('Y-m-d');
        $row   = $this->get($userId);

        if ($row === null) {
            // First ever check-in
            $stmt = $this->db()->prepare(
                'INSERT INTO daily_streaks
                 (user_id, current_streak, longest_streak, last_checked_in, updated_at)
                 VALUES (?, 1, 1, ?, CURRENT_TIMESTAMP)'
            );
            $stmt->execute([$userId, $today]);
            return true;
        }

        $last = (string)$row['last_checked_in'];

        if ($last === $today) {
            return false; // already checked in today
        }

        // Calculate gap in days
        $gap = (int)(
            (new \DateTimeImmutable($today))->diff(new \DateTimeImmutable($last))->days
        );

        $newCurrent = ($gap === 1) ? (int)$row['current_streak'] + 1 : 1;
        $newLongest = max($newCurrent, (int)$row['longest_streak']);

        $stmt = $this->db()->prepare(
            'UPDATE daily_streaks
             SET current_streak  = ?,
                 longest_streak  = ?,
                 last_checked_in = ?,
                 updated_at      = CURRENT_TIMESTAMP
             WHERE user_id = ?'
        );
        $stmt->execute([$newCurrent, $newLongest, $today, $userId]);
        return true;
    }

    // ── queries ───────────────────────────────────────────────────────────────

    /**
     * Return the full streak row, or null if the user has never checked in.
     *
     * @return array<string,mixed>|null
     */
    public function get(string $userId): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM daily_streaks WHERE user_id = ?'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Return the current consecutive-day streak (0 if never checked in).
     */
    public function currentStreak(string $userId): int
    {
        $row = $this->get($userId);
        return $row !== null ? (int)$row['current_streak'] : 0;
    }

    /**
     * Return the all-time longest streak (0 if never checked in).
     */
    public function longestStreak(string $userId): int
    {
        $row = $this->get($userId);
        return $row !== null ? (int)$row['longest_streak'] : 0;
    }

    /**
     * Return the date of the last check-in (Y-m-d), or null if never checked in.
     */
    public function lastCheckedIn(string $userId): ?string
    {
        $row = $this->get($userId);
        return $row !== null ? (string)$row['last_checked_in'] : null;
    }

    // ── reset ─────────────────────────────────────────────────────────────────

    /**
     * Reset the current streak to 0 (admin action; longest streak is preserved).
     *
     * Returns true if the row existed and was updated.
     */
    public function reset(string $userId): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE daily_streaks
             SET current_streak = 0, updated_at = CURRENT_TIMESTAMP
             WHERE user_id = ?'
        );
        $stmt->execute([$userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Remove the streak record entirely (hard delete).
     */
    public function remove(string $userId): bool
    {
        $stmt = $this->db()->prepare(
            'DELETE FROM daily_streaks WHERE user_id = ?'
        );
        $stmt->execute([$userId]);
        return $stmt->rowCount() > 0;
    }
}
