<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\DbUpsert;
use Nene\Xion\PdoConnection;
use PDO;

/**
 * ShiftRoster — staff shift scheduling with coverage tracking.
 *
 * Models named shifts on a given day (e.g. 2026-06-01 "morning") each with a
 * required headcount, and assigns workers to them. Prevents double-assigning
 * the same worker to one shift, reports coverage (assigned vs. required), and
 * lists a worker's shifts. Distinct from `TimeSlot` (appointment booking by a
 * customer against availability) and `AccessSchedule` (time-window access
 * control): this is the staffing roster.
 *
 * ## Usage
 *
 * ```php
 * $r = new ShiftRoster($pdo);
 *
 * $r->defineShift('2026-06-01', 'morning', required: 2);
 * $r->assign('2026-06-01', 'morning', 'alice'); // true
 * $r->assign('2026-06-01', 'morning', 'bob');   // true
 *
 * $r->isCovered('2026-06-01', 'morning');       // true (2 >= 2)
 * $r->coverage('2026-06-01', 'morning');        // ['required'=>2,'assigned'=>2,'short'=>0]
 * $r->assignees('2026-06-01', 'morning');       // ['alice','bob']
 * $r->shiftsFor('alice', '2026-06-01');         // ['morning']
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE roster_shifts (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     shift_date CHAR(10)     NOT NULL,
 *     name       VARCHAR(100) NOT NULL,
 *     required   INTEGER      NOT NULL DEFAULT 1,
 *     UNIQUE (shift_date, name)
 * );
 * CREATE TABLE roster_assignments (
 *     id       INTEGER PRIMARY KEY AUTOINCREMENT,
 *     shift_id BIGINT       NOT NULL,
 *     worker   VARCHAR(190) NOT NULL,
 *     UNIQUE (shift_id, worker)
 * );
 * ```
 */
final class ShiftRoster
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Define (or update the required headcount of) a shift. Idempotent on
     * (date, name).
     *
     * @param  string $date     Shift day 'Y-m-d'.
     * @param  string $name     Shift name (e.g. 'morning').
     * @param  int    $required Headcount required (>= 1).
     * @return int              The shift id.
     * @throws \InvalidArgumentException on empty fields or non-positive headcount.
     */
    public function defineShift(string $date, string $name, int $required = 1): int
    {
        $date = $this->nonEmpty($date, 'Date');
        $name = $this->nonEmpty($name, 'Name');
        if ($required < 1) {
            throw new \InvalidArgumentException('Required headcount must be at least 1.');
        }

        DbUpsert::run(
            $this->db(),
            table: 'roster_shifts',
            data: ['shift_date' => $date, 'name' => $name, 'required' => $required],
            conflictCols: ['shift_date', 'name'],
            updateCols: ['required'],
        );

        $id = $this->shiftId($date, $name);
        if ($id === null) {
            throw new \RuntimeException('Failed to persist shift.');
        }

        return $id;
    }

    /**
     * Assign a worker to a defined shift. Idempotent.
     *
     * @return bool True if newly assigned; false if already on this shift.
     * @throws \InvalidArgumentException if the shift is not defined or worker empty.
     */
    public function assign(string $date, string $name, string $worker): bool
    {
        $worker = $this->nonEmpty($worker, 'Worker');
        $shift  = $this->requireShiftId($date, $name);

        $stmt = $this->db()->prepare(
            'INSERT INTO roster_assignments (shift_id, worker) VALUES (?, ?)
             ON CONFLICT (shift_id, worker) DO NOTHING'
        );
        $stmt->execute([$shift, $worker]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Remove a worker from a shift.
     *
     * @return bool True if a row was removed.
     * @throws \InvalidArgumentException if the shift is not defined.
     */
    public function unassign(string $date, string $name, string $worker): bool
    {
        $worker = $this->nonEmpty($worker, 'Worker');
        $shift  = $this->requireShiftId($date, $name);

        $stmt = $this->db()->prepare('DELETE FROM roster_assignments WHERE shift_id = ? AND worker = ?');
        $stmt->execute([$shift, $worker]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Workers assigned to a shift, in assignment order.
     *
     * @return array<int,string>
     */
    public function assignees(string $date, string $name): array
    {
        $shift = $this->shiftId($this->nonEmpty($date, 'Date'), $this->nonEmpty($name, 'Name'));
        if ($shift === null) {
            return [];
        }
        $stmt = $this->db()->prepare('SELECT worker FROM roster_assignments WHERE shift_id = ? ORDER BY id ASC');
        $stmt->execute([$shift]);

        return array_map(static fn ($w): string => (string)$w, $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Whether a shift has at least its required headcount assigned. False if
     * the shift is undefined.
     */
    public function isCovered(string $date, string $name): bool
    {
        $c = $this->coverage($date, $name);

        return $c !== null && $c['short'] === 0;
    }

    /**
     * Coverage for a shift, or null if it is undefined.
     *
     * @return array{required:int,assigned:int,short:int}|null
     */
    public function coverage(string $date, string $name): ?array
    {
        $date = $this->nonEmpty($date, 'Date');
        $name = $this->nonEmpty($name, 'Name');

        $stmt = $this->db()->prepare('SELECT id, required FROM roster_shifts WHERE shift_date = ? AND name = ?');
        $stmt->execute([$date, $name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        $required = (int)$row['required'];
        $cnt      = $this->db()->prepare('SELECT COUNT(*) FROM roster_assignments WHERE shift_id = ?');
        $cnt->execute([(int)$row['id']]);
        $assigned = (int)$cnt->fetchColumn();
        $short    = $required - $assigned;

        return [
            'required' => $required,
            'assigned' => $assigned,
            'short'    => $short > 0 ? $short : 0,
        ];
    }

    /**
     * Named shifts a worker is assigned to on a given day, in shift-name order.
     *
     * @return array<int,string>
     */
    public function shiftsFor(string $worker, string $date): array
    {
        $worker = $this->nonEmpty($worker, 'Worker');
        $date   = $this->nonEmpty($date, 'Date');

        $stmt = $this->db()->prepare(
            'SELECT s.name FROM roster_shifts s
             JOIN roster_assignments a ON a.shift_id = s.id
             WHERE s.shift_date = ? AND a.worker = ?
             ORDER BY s.name ASC'
        );
        $stmt->execute([$date, $worker]);

        return array_map(static fn ($n): string => (string)$n, $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    // ── private ───────────────────────────────────────────────────────────────

    private function requireShiftId(string $date, string $name): int
    {
        $id = $this->shiftId($this->nonEmpty($date, 'Date'), $this->nonEmpty($name, 'Name'));
        if ($id === null) {
            throw new \InvalidArgumentException("Shift not defined: {$date} {$name}");
        }

        return $id;
    }

    private function shiftId(string $date, string $name): ?int
    {
        $stmt = $this->db()->prepare('SELECT id FROM roster_shifts WHERE shift_date = ? AND name = ?');
        $stmt->execute([$date, $name]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int)$id;
    }

    private function nonEmpty(string $value, string $label): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new \InvalidArgumentException("{$label} must not be empty.");
        }

        return $value;
    }

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
