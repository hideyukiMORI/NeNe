<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\DbUpsert;
use Nene\Xion\PdoConnection;
use PDO;

/**
 * SpaceOccupancy — live headcount for a capacity-limited physical space.
 *
 * Tracks the current (anonymous) occupancy of a named space against a hard
 * capacity: `enter()` admits people only while room remains, `leave()` releases
 * them, and a high-water `peak` is recorded. Distinct from `PresenceChannel`
 * (which tracks *which identities* are present, with no capacity ceiling) and
 * `EventTicket` (pre-sold tickets with check-in): this is a real-time counter
 * for rooms, parking lots, gyms, or venues.
 *
 * The counter mutations are single guarded `UPDATE` statements, so admission is
 * atomic and never overshoots capacity even under concurrency.
 *
 * ## Usage
 *
 * ```php
 * $o = new SpaceOccupancy($pdo);
 *
 * $o->defineSpace('gym', capacity: 50);
 * $o->enter('gym');        // true  — 1/50
 * $o->enter('gym', 49);    // true  — 50/50
 * $o->enter('gym');        // false — full
 * $o->isFull('gym');       // true
 * $o->leave('gym', 2);     // true  — 48/50
 * $o->available('gym');    // 2
 * $o->peak('gym');         // 50
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE space_occupancy (
 *     id       INTEGER PRIMARY KEY AUTOINCREMENT,
 *     space    VARCHAR(150) NOT NULL,
 *     capacity INTEGER      NOT NULL,
 *     current  INTEGER      NOT NULL DEFAULT 0,
 *     peak     INTEGER      NOT NULL DEFAULT 0,
 *     UNIQUE (space)
 * );
 * ```
 */
final class SpaceOccupancy
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Define a space (or update its capacity). Idempotent on the space name;
     * the live count and peak are preserved across capacity changes.
     *
     * @param  string $space    Space name.
     * @param  int    $capacity Maximum occupancy (>= 1).
     * @return int              The space id.
     * @throws \InvalidArgumentException on empty name or non-positive capacity.
     */
    public function defineSpace(string $space, int $capacity): int
    {
        $space = $this->nonEmpty($space, 'Space');
        if ($capacity < 1) {
            throw new \InvalidArgumentException('Capacity must be at least 1.');
        }

        DbUpsert::run(
            $this->db(),
            table: 'space_occupancy',
            data: ['space' => $space, 'capacity' => $capacity],
            conflictCols: ['space'],
            updateCols: ['capacity'],
        );

        $id = $this->spaceId($space);
        if ($id === null) {
            throw new \RuntimeException('Failed to persist space.');
        }

        return $id;
    }

    /**
     * Admit `count` occupants if (and only if) they all fit. Atomic.
     *
     * @return bool True if admitted; false if it would exceed capacity.
     * @throws \InvalidArgumentException on unknown space or non-positive count.
     */
    public function enter(string $space, int $count = 1): bool
    {
        $space = $this->nonEmpty($space, 'Space');
        $this->requirePositive($count);
        $this->requireSpace($space);

        $stmt = $this->db()->prepare(
            'UPDATE space_occupancy
                SET current = current + :n,
                    peak    = CASE WHEN current + :n > peak THEN current + :n ELSE peak END
              WHERE space = :s AND current + :n <= capacity'
        );
        $stmt->bindValue(':n', $count, PDO::PARAM_INT);
        $stmt->bindValue(':s', $space, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    /**
     * Release `count` occupants (never below zero).
     *
     * @return bool True if the space exists; false otherwise.
     * @throws \InvalidArgumentException on non-positive count.
     */
    public function leave(string $space, int $count = 1): bool
    {
        $space = $this->nonEmpty($space, 'Space');
        $this->requirePositive($count);
        if ($this->spaceId($space) === null) {
            return false;
        }

        $stmt = $this->db()->prepare(
            'UPDATE space_occupancy
                SET current = CASE WHEN current >= :n THEN current - :n ELSE 0 END
              WHERE space = :s'
        );
        $stmt->bindValue(':n', $count, PDO::PARAM_INT);
        $stmt->bindValue(':s', $space, PDO::PARAM_STR);
        $stmt->execute();

        return true;
    }

    /**
     * Reset the live count to zero (peak is retained).
     */
    public function reset(string $space): bool
    {
        $space = $this->nonEmpty($space, 'Space');
        if ($this->spaceId($space) === null) {
            return false;
        }
        $this->db()->prepare('UPDATE space_occupancy SET current = 0 WHERE space = ?')->execute([$space]);

        return true;
    }

    /**
     * Current occupancy (0 for an unknown space).
     */
    public function current(string $space): int
    {
        return $this->column($space, 'current') ?? 0;
    }

    /**
     * Remaining capacity (0 for an unknown space or when full).
     */
    public function available(string $space): int
    {
        $row = $this->row($space);
        if ($row === null) {
            return 0;
        }
        $left = $row['capacity'] - $row['current'];

        return $left > 0 ? $left : 0;
    }

    /**
     * Whether the space is at (or over) capacity. False for an unknown space.
     */
    public function isFull(string $space): bool
    {
        $row = $this->row($space);

        return $row !== null && $row['current'] >= $row['capacity'];
    }

    /**
     * Highest occupancy ever recorded (0 for an unknown space).
     */
    public function peak(string $space): int
    {
        return $this->column($space, 'peak') ?? 0;
    }

    // ── private ───────────────────────────────────────────────────────────────

    /**
     * @return array{capacity:int,current:int}|null
     */
    private function row(string $space): ?array
    {
        $stmt = $this->db()->prepare('SELECT capacity, current FROM space_occupancy WHERE space = ?');
        $stmt->execute([$this->nonEmpty($space, 'Space')]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($r === false) {
            return null;
        }

        return ['capacity' => (int)$r['capacity'], 'current' => (int)$r['current']];
    }

    private function column(string $space, string $col): ?int
    {
        // $col is a fixed internal literal ('current' | 'peak'), never user input.
        $stmt = $this->db()->prepare("SELECT {$col} FROM space_occupancy WHERE space = ?");
        $stmt->execute([$this->nonEmpty($space, 'Space')]);
        $v = $stmt->fetchColumn();

        return $v === false ? null : (int)$v;
    }

    private function spaceId(string $space): ?int
    {
        $stmt = $this->db()->prepare('SELECT id FROM space_occupancy WHERE space = ?');
        $stmt->execute([$space]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int)$id;
    }

    private function requireSpace(string $space): void
    {
        if ($this->spaceId($space) === null) {
            throw new \InvalidArgumentException("Unknown space: {$space}");
        }
    }

    private function requirePositive(int $count): void
    {
        if ($count < 1) {
            throw new \InvalidArgumentException('Count must be at least 1.');
        }
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
