<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * TimeSlot — appointment/time slot booking with availability.
 *
 * Defines available time slots and manages bookings per resource.
 * A slot is a specific (date, start_time, end_time) window for a given
 * resource. Each slot has a capacity and tracks how many bookings exist.
 *
 * Distinct from EventTicket (event-centric with venue capacity).
 *
 * ## Usage
 *
 * ```php
 * $ts = new TimeSlot($pdo);
 *
 * $slotId = $ts->createSlot('doctor-1', '2026-06-01', '09:00', '09:30', 1);
 * $bookId = $ts->book($slotId, 'patient-42');
 * $ts->cancel($bookId);
 *
 * $avail = $ts->availableSlots('doctor-1', '2026-06-01');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE time_slots (
 *     id           INTEGER PRIMARY KEY AUTOINCREMENT,
 *     resource_ref VARCHAR(255) NOT NULL,
 *     slot_date    DATE         NOT NULL,
 *     start_time   VARCHAR(5)   NOT NULL,
 *     end_time     VARCHAR(5)   NOT NULL,
 *     capacity     INTEGER      NOT NULL DEFAULT 1,
 *     booked       INTEGER      NOT NULL DEFAULT 0,
 *     created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 *
 * CREATE TABLE time_slot_bookings (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     slot_id    INTEGER      NOT NULL,
 *     user_id    VARCHAR(255) NOT NULL,
 *     note       TEXT         NULL,
 *     status     VARCHAR(20)  NOT NULL DEFAULT 'confirmed',
 *     created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class TimeSlot
{
    public const BOOKING_CONFIRMED = 'confirmed';
    public const BOOKING_CANCELLED = 'cancelled';

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── slot management ───────────────────────────────────────────────────────

    /**
     * Create a time slot.
     *
     * @return int Slot ID.
     * @throws \InvalidArgumentException on empty resource_ref.
     */
    public function createSlot(
        string $resourceRef,
        string $slotDate,
        string $startTime,
        string $endTime,
        int $capacity = 1
    ): int {
        $resourceRef = trim($resourceRef);
        if ($resourceRef === '') {
            throw new \InvalidArgumentException('resource_ref must not be empty.');
        }
        if ($capacity < 1) {
            throw new \InvalidArgumentException('capacity must be at least 1.');
        }
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'INSERT INTO time_slots (resource_ref, slot_date, start_time, end_time, capacity, created_at)
             VALUES (:res, :date, :start, :end, :cap, :now)'
        );
        $stmt->execute([
            ':res'   => $resourceRef,
            ':date'  => $slotDate,
            ':start' => $startTime,
            ':end'   => $endTime,
            ':cap'   => $capacity,
            ':now'   => $now,
        ]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Return a slot by ID.
     *
     * @return array<string,mixed>|null
     */
    public function findSlot(int $id): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, resource_ref, slot_date, start_time, end_time, capacity, booked, created_at
             FROM time_slots WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Delete a slot and all its bookings.
     *
     * @return bool True if found and deleted.
     */
    public function deleteSlot(int $id): bool
    {
        $this->db()->prepare('DELETE FROM time_slot_bookings WHERE slot_id = :id')->execute([':id' => $id]);
        $stmt = $this->db()->prepare('DELETE FROM time_slots WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Return available (not fully booked) slots for a resource on a date.
     *
     * @return list<array<string,mixed>>
     */
    public function availableSlots(string $resourceRef, string $slotDate): array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, resource_ref, slot_date, start_time, end_time, capacity, booked, created_at
             FROM time_slots
             WHERE resource_ref = :res AND slot_date = :date AND booked < capacity
             ORDER BY start_time ASC'
        );
        $stmt->execute([':res' => trim($resourceRef), ':date' => $slotDate]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // ── booking ───────────────────────────────────────────────────────────────

    /**
     * Book a slot for a user. Returns false if the slot is full.
     *
     * @return int|false Booking ID on success, false if slot is full or missing.
     * @throws \InvalidArgumentException on empty user_id.
     */
    public function book(int $slotId, string $userId, ?string $note = null): int|false
    {
        $userId = trim($userId);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }

        // Attempt to increment booked count atomically
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'UPDATE time_slots SET booked = booked + 1
             WHERE id = :id AND booked < capacity'
        );
        $stmt->execute([':id' => $slotId]);
        if ($stmt->rowCount() === 0) {
            return false;
        }

        $bStmt = $this->db()->prepare(
            'INSERT INTO time_slot_bookings (slot_id, user_id, note, created_at)
             VALUES (:slot, :uid, :note, :now)'
        );
        $bStmt->execute([':slot' => $slotId, ':uid' => $userId, ':note' => $note, ':now' => $now]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Cancel a booking. Returns false if already cancelled or not found.
     *
     * @return bool True if found (confirmed) and cancelled.
     */
    public function cancel(int $bookingId): bool
    {
        // Get booking to find slot_id
        $stmt = $this->db()->prepare(
            "SELECT slot_id FROM time_slot_bookings WHERE id = :id AND status = 'confirmed'"
        );
        $stmt->execute([':id' => $bookingId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return false;
        }

        $this->db()->prepare(
            "UPDATE time_slot_bookings SET status = 'cancelled' WHERE id = :id"
        )->execute([':id' => $bookingId]);

        // Decrement booked count on slot
        $this->db()->prepare(
            'UPDATE time_slots SET booked = booked - 1 WHERE id = :id AND booked > 0'
        )->execute([':id' => $row['slot_id']]);

        return true;
    }

    /**
     * Return all bookings for a slot.
     *
     * @return list<array<string,mixed>>
     */
    public function bookingsFor(int $slotId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, slot_id, user_id, note, status, created_at
             FROM time_slot_bookings WHERE slot_id = :sid ORDER BY id ASC'
        );
        $stmt->execute([':sid' => $slotId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // ── internal helpers ──────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
