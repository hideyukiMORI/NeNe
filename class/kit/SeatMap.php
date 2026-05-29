<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * SeatMap — named-seat reservation for a fixed venue layout.
 *
 * Models a venue as a fixed set of named seats (e.g. "A1".."A10") and lets a
 * holder reserve a *specific* seat, release it, and query who holds what.
 * Reservation is a single guarded UPDATE, so two holders can never claim the
 * same seat. Distinct from `EventTicket` (a capacity *count* with check-in, no
 * seat identity), `ResourceReservation` (time-bounded, one shared resource),
 * and `TimeSlot` (appointment booking): this is assigned seating.
 *
 * ## Usage
 *
 * ```php
 * $m = new SeatMap($pdo);
 *
 * $m->addRow('hall', 'A', 10);            // seats A1..A10
 * $m->reserve('hall', 'A1', 'alice');     // true
 * $m->reserve('hall', 'A1', 'bob');       // false — taken
 * $m->holderOf('hall', 'A1');             // 'alice'
 * $m->availableSeats('hall');             // ['A2','A3',...]
 * $m->release('hall', 'A1');              // true
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE seat_map (
 *     id     INTEGER PRIMARY KEY AUTOINCREMENT,
 *     venue  VARCHAR(150) NOT NULL,
 *     seat   VARCHAR(50)  NOT NULL,
 *     holder VARCHAR(190) NULL,
 *     UNIQUE (venue, seat)
 * );
 * ```
 */
final class SeatMap
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Add a single seat to a venue. Idempotent (existing seat is left as-is,
     * holder preserved).
     *
     * @throws \InvalidArgumentException on empty venue/seat.
     */
    public function addSeat(string $venue, string $seat): void
    {
        $venue = $this->nonEmpty($venue, 'Venue');
        $seat  = $this->nonEmpty($seat, 'Seat');

        $stmt = $this->db()->prepare(
            'INSERT INTO seat_map (venue, seat) VALUES (?, ?) ON CONFLICT (venue, seat) DO NOTHING'
        );
        $stmt->execute([$venue, $seat]);
    }

    /**
     * Add a numbered row of seats: `$row . 1` .. `$row . $count`.
     *
     * @throws \InvalidArgumentException on empty row or non-positive count.
     */
    public function addRow(string $venue, string $row, int $count): void
    {
        $row = $this->nonEmpty($row, 'Row');
        if ($count < 1) {
            throw new \InvalidArgumentException('Count must be at least 1.');
        }
        for ($i = 1; $i <= $count; $i++) {
            $this->addSeat($venue, $row . $i);
        }
    }

    /**
     * Reserve a specific seat for a holder, if it is currently free.
     *
     * @return bool True if reserved; false if already taken.
     * @throws \InvalidArgumentException if the seat does not exist, or empty holder.
     */
    public function reserve(string $venue, string $seat, string $holder): bool
    {
        $venue  = $this->nonEmpty($venue, 'Venue');
        $seat   = $this->nonEmpty($seat, 'Seat');
        $holder = $this->nonEmpty($holder, 'Holder');
        if (!$this->seatExists($venue, $seat)) {
            throw new \InvalidArgumentException("No such seat: {$venue} {$seat}");
        }

        $stmt = $this->db()->prepare(
            'UPDATE seat_map SET holder = ? WHERE venue = ? AND seat = ? AND holder IS NULL'
        );
        $stmt->execute([$holder, $venue, $seat]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Release a seat back to the pool.
     *
     * @return bool True if it was reserved (now freed); false if already free
     *              or the seat does not exist.
     */
    public function release(string $venue, string $seat): bool
    {
        $venue = $this->nonEmpty($venue, 'Venue');
        $seat  = $this->nonEmpty($seat, 'Seat');

        $stmt = $this->db()->prepare(
            'UPDATE seat_map SET holder = NULL WHERE venue = ? AND seat = ? AND holder IS NOT NULL'
        );
        $stmt->execute([$venue, $seat]);

        return $stmt->rowCount() > 0;
    }

    /**
     * The holder of a seat, or null if free / nonexistent.
     */
    public function holderOf(string $venue, string $seat): ?string
    {
        $stmt = $this->db()->prepare('SELECT holder FROM seat_map WHERE venue = ? AND seat = ?');
        $stmt->execute([$this->nonEmpty($venue, 'Venue'), $this->nonEmpty($seat, 'Seat')]);
        $h = $stmt->fetchColumn();

        return $h === false || $h === null ? null : (string)$h;
    }

    /**
     * Whether a seat exists and is currently unreserved.
     */
    public function isAvailable(string $venue, string $seat): bool
    {
        $stmt = $this->db()->prepare('SELECT holder FROM seat_map WHERE venue = ? AND seat = ?');
        $stmt->execute([$this->nonEmpty($venue, 'Venue'), $this->nonEmpty($seat, 'Seat')]);
        $r = $stmt->fetch(PDO::FETCH_NUM);

        return $r !== false && $r[0] === null;
    }

    /**
     * Free seats in a venue, in seat order.
     *
     * @return array<int,string>
     */
    public function availableSeats(string $venue): array
    {
        $stmt = $this->db()->prepare(
            'SELECT seat FROM seat_map WHERE venue = ? AND holder IS NULL ORDER BY seat ASC'
        );
        $stmt->execute([$this->nonEmpty($venue, 'Venue')]);

        return array_map(static fn ($s): string => (string)$s, $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Reserved seats in a venue with their holders, in seat order.
     *
     * @return array<int,array{seat:string,holder:string}>
     */
    public function reservedSeats(string $venue): array
    {
        $stmt = $this->db()->prepare(
            'SELECT seat, holder FROM seat_map WHERE venue = ? AND holder IS NOT NULL ORDER BY seat ASC'
        );
        $stmt->execute([$this->nonEmpty($venue, 'Venue')]);

        $out = [];
        /** @var array<string,mixed> $r */
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = ['seat' => (string)$r['seat'], 'holder' => (string)$r['holder']];
        }

        return $out;
    }

    /**
     * Seats held by a given holder in a venue, in seat order.
     *
     * @return array<int,string>
     */
    public function seatsOf(string $venue, string $holder): array
    {
        $stmt = $this->db()->prepare(
            'SELECT seat FROM seat_map WHERE venue = ? AND holder = ? ORDER BY seat ASC'
        );
        $stmt->execute([$this->nonEmpty($venue, 'Venue'), $this->nonEmpty($holder, 'Holder')]);

        return array_map(static fn ($s): string => (string)$s, $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    // ── private ───────────────────────────────────────────────────────────────

    private function seatExists(string $venue, string $seat): bool
    {
        $stmt = $this->db()->prepare('SELECT 1 FROM seat_map WHERE venue = ? AND seat = ? LIMIT 1');
        $stmt->execute([$venue, $seat]);

        return $stmt->fetchColumn() !== false;
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
