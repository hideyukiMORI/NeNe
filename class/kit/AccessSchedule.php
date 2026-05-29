<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * AccessSchedule — recurring time-window access control for users and resources.
 *
 * Defines recurring weekly access windows (e.g. "Mon–Fri 09:00–17:00")
 * and checks whether a given datetime falls within an allowed window.
 * Useful for time-restricted feature access, maintenance windows,
 * and shift-based permissions.
 *
 * Distinct from:
 * - `AccessGrant`         (time-bounded delegation, ad-hoc)
 * - `ResourceReservation` (single-occurrence slot booking)
 * - `AccessControl`       (per-resource subject ACL)
 *
 * ## Usage
 *
 * ```php
 * $as = new AccessSchedule($pdo);
 *
 * $id = $as->create('user-1', 'office-wifi', [1,2,3,4,5], '09:00', '17:00');
 * $as->isAllowed('user-1', 'office-wifi'); // depends on current day/time
 * $as->isAllowedAt('user-1', 'office-wifi', '2026-06-15 10:00:00'); // Monday 10am
 * ```
 *
 * ## Day-of-week encoding
 * 0 = Sunday, 1 = Monday, ..., 6 = Saturday (ISO-compatible).
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE access_schedules (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     subject_id  VARCHAR(255) NOT NULL,
 *     resource    VARCHAR(255) NOT NULL,
 *     days        VARCHAR(20)  NOT NULL,
 *     time_start  VARCHAR(8)   NOT NULL,
 *     time_end    VARCHAR(8)   NOT NULL,
 *     active      INTEGER      NOT NULL DEFAULT 1,
 *     created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class AccessSchedule
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Create an access schedule for a subject/resource pair.
     *
     * @param  list<int> $days Days of week (0=Sun … 6=Sat).
     * @param  string    $timeStart 'HH:MM' start time (inclusive).
     * @param  string    $timeEnd   'HH:MM' end time (exclusive).
     * @return int New schedule ID.
     * @throws \InvalidArgumentException on empty subjectId/resource, no days, or bad time format.
     */
    public function create(
        string $subjectId,
        string $resource,
        array $days,
        string $timeStart,
        string $timeEnd
    ): int {
        $subjectId = trim($subjectId);
        $resource  = trim($resource);
        if ($subjectId === '') {
            throw new \InvalidArgumentException('subject_id must not be empty.');
        }
        if ($resource === '') {
            throw new \InvalidArgumentException('resource must not be empty.');
        }
        if (count($days) === 0) {
            throw new \InvalidArgumentException('At least one day must be specified.');
        }
        if (!$this->isValidTime($timeStart) || !$this->isValidTime($timeEnd)) {
            throw new \InvalidArgumentException('time_start and time_end must be HH:MM format.');
        }

        // Normalize days: unique, sorted, within 0-6
        $days = array_values(array_unique(array_filter($days, fn ($d) => $d >= 0 && $d <= 6)));
        sort($days);

        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'INSERT INTO access_schedules (subject_id, resource, days, time_start, time_end, active, created_at)
             VALUES (:sid, :res, :days, :ts, :te, 1, :now)'
        );
        $stmt->execute([
            ':sid'  => $subjectId,
            ':res'  => $resource,
            ':days' => implode(',', $days),
            ':ts'   => $timeStart,
            ':te'   => $timeEnd,
            ':now'  => $now,
        ]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Retrieve a schedule by ID.
     *
     * @return array<string,mixed>|null
     */
    public function get(int $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM access_schedules WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Check whether subject/resource access is allowed right now.
     */
    public function isAllowed(string $subjectId, string $resource): bool
    {
        return $this->isAllowedAt($subjectId, $resource, (new \DateTimeImmutable())->format('Y-m-d H:i:s'));
    }

    /**
     * Check whether subject/resource access is allowed at a given datetime.
     *
     * @param  string $datetime 'Y-m-d H:i:s' or 'Y-m-d H:i' format.
     */
    public function isAllowedAt(string $subjectId, string $resource, string $datetime): bool
    {
        $dt      = new \DateTimeImmutable($datetime);
        $dayOfWk = (int)$dt->format('w'); // 0=Sun ... 6=Sat
        $timeNow = $dt->format('H:i');

        $schedules = $this->activeFor(trim($subjectId), trim($resource));
        foreach ($schedules as $schedule) {
            $days = explode(',', (string)$schedule['days']);
            if (!in_array((string)$dayOfWk, $days, true)) {
                continue;
            }
            if ($timeNow >= (string)$schedule['time_start'] && $timeNow < (string)$schedule['time_end']) {
                return true;
            }
        }
        return false;
    }

    /**
     * All active schedules for a subject/resource pair.
     *
     * @return list<array<string,mixed>>
     */
    public function activeFor(string $subjectId, string $resource): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM access_schedules
             WHERE subject_id = :sid AND resource = :res AND active = 1
             ORDER BY id ASC'
        );
        $stmt->execute([':sid' => trim($subjectId), ':res' => trim($resource)]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * All schedules for a subject (all resources).
     *
     * @return list<array<string,mixed>>
     */
    public function forSubject(string $subjectId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM access_schedules WHERE subject_id = :sid ORDER BY resource ASC, id ASC'
        );
        $stmt->execute([':sid' => trim($subjectId)]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Deactivate a schedule (stop it from being checked).
     *
     * @return bool True if found and active.
     */
    public function deactivate(int $id): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE access_schedules SET active = 0 WHERE id = :id AND active = 1'
        );
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Reactivate a deactivated schedule.
     *
     * @return bool True if found and inactive.
     */
    public function activate(int $id): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE access_schedules SET active = 1 WHERE id = :id AND active = 0'
        );
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Delete a schedule.
     *
     * @return bool True if found.
     */
    public function delete(int $id): bool
    {
        $stmt = $this->db()->prepare('DELETE FROM access_schedules WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function isValidTime(string $time): bool
    {
        return (bool)preg_match('/^\d{2}:\d{2}$/', $time);
    }

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
