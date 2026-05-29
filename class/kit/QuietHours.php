<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\DbUpsert;
use Nene\Xion\PdoConnection;
use PDO;

/**
 * QuietHours — per-user do-not-disturb time-of-day window.
 *
 * Stores a recurring daily quiet window per user (e.g. 22:00–07:00) so a
 * notification pipeline can suppress or defer non-urgent messages during it.
 * Distinct from `MaintenanceWindow` (FT269), which models absolute,
 * service-scoped one-off intervals: this is a recurring per-user time-of-day
 * range.
 *
 * Windows are stored as minutes-from-midnight `[start, end)`. Overnight
 * windows (start > end, e.g. 22:00–07:00) are supported and wrap across
 * midnight. A zero-length window (start == end) is treated as "never quiet".
 * The time zone is stored as metadata; callers pass the user's local
 * wall-clock time to {@see QuietHours::isQuiet()}.
 *
 * ## Usage
 *
 * ```php
 * $qh = new QuietHours($pdo);
 *
 * $qh->set(42, '22:00', '07:00', 'Asia/Tokyo'); // overnight window
 *
 * $qh->isQuiet(42, '23:30'); // true
 * $qh->isQuiet(42, '06:59'); // true
 * $qh->isQuiet(42, '07:00'); // false (end exclusive)
 * $qh->isQuiet(42, '12:00'); // false
 *
 * $qh->window(42);   // ['start' => '22:00', 'end' => '07:00', 'tz' => 'Asia/Tokyo']
 * $qh->clear(42);
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE quiet_hours (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     user_id    BIGINT      NOT NULL,
 *     start_min  INTEGER     NOT NULL,
 *     end_min    INTEGER     NOT NULL,
 *     tz         VARCHAR(64) NOT NULL DEFAULT 'UTC',
 *     updated_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (user_id)
 * );
 * ```
 */
final class QuietHours
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Set (or replace) a user's quiet window. Idempotent per user.
     *
     * @param  int    $userId User id.
     * @param  string $start  Window start as 'HH:MM' (inclusive).
     * @param  string $end    Window end as 'HH:MM' (exclusive); may be < start (overnight).
     * @param  string $tz     IANA time zone metadata (default 'UTC').
     * @throws \InvalidArgumentException on malformed time or empty tz.
     */
    public function set(int $userId, string $start, string $end, string $tz = 'UTC'): void
    {
        $startMin = $this->toMinutes($start);
        $endMin   = $this->toMinutes($end);
        $tz       = trim($tz);
        if ($tz === '') {
            throw new \InvalidArgumentException('Time zone must not be empty.');
        }

        DbUpsert::run(
            $this->db(),
            table:        'quiet_hours',
            data:         ['user_id' => $userId, 'start_min' => $startMin, 'end_min' => $endMin, 'tz' => $tz],
            conflictCols: ['user_id'],
            updateCols:   ['start_min', 'end_min', 'tz'],
            updateExprs:  ['updated_at' => 'CURRENT_TIMESTAMP'],
        );
    }

    /**
     * Whether a local wall-clock time falls inside the user's quiet window.
     *
     * Returns false if the user has no window. The window is half-open
     * `[start, end)`; overnight windows wrap; a zero-length window is never quiet.
     *
     * @param int    $userId User id.
     * @param string $at     Local time as 'HH:MM'.
     */
    public function isQuiet(int $userId, string $at): bool
    {
        $row = $this->fetch($userId);
        if ($row === null) {
            return false;
        }

        $t     = $this->toMinutes($at);
        $start = (int)$row['start_min'];
        $end   = (int)$row['end_min'];

        if ($start === $end) {
            return false; // zero-length window
        }
        if ($start < $end) {
            return $t >= $start && $t < $end;
        }

        // Overnight window (wraps past midnight).
        return $t >= $start || $t < $end;
    }

    /**
     * Whether the user has a quiet window configured.
     */
    public function hasWindow(int $userId): bool
    {
        return $this->fetch($userId) !== null;
    }

    /**
     * Return the user's window as 'HH:MM' strings + tz, or null.
     *
     * @return array{start:string,end:string,tz:string}|null
     */
    public function window(int $userId): ?array
    {
        $row = $this->fetch($userId);
        if ($row === null) {
            return null;
        }

        return [
            'start' => $this->toClock((int)$row['start_min']),
            'end'   => $this->toClock((int)$row['end_min']),
            'tz'    => (string)$row['tz'],
        ];
    }

    /**
     * Remove a user's quiet window. No-op if absent.
     */
    public function clear(int $userId): void
    {
        $stmt = $this->db()->prepare('DELETE FROM quiet_hours WHERE user_id = ?');
        $stmt->execute([$userId]);
    }

    // ── private ───────────────────────────────────────────────────────────────

    /**
     * @return array<string,mixed>|null
     */
    private function fetch(int $userId): ?array
    {
        $stmt = $this->db()->prepare('SELECT start_min, end_min, tz FROM quiet_hours WHERE user_id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private function toMinutes(string $clock): int
    {
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', trim($clock), $m) !== 1) {
            throw new \InvalidArgumentException("Invalid time (expected HH:MM 00:00–23:59): {$clock}");
        }

        return ((int)$m[1]) * 60 + (int)$m[2];
    }

    private function toClock(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
