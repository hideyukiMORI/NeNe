<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * EventTicket — event ticketing with capacity management and check-in tracking.
 *
 * Manages ticket issuance for named events with optional capacity limits.
 * Each ticket has a unique code for check-in. Cancelled tickets free up capacity.
 *
 * Status lifecycle: `issued` → `checked_in` (or `cancelled`)
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE event_tickets (
 *     id            INTEGER PRIMARY KEY AUTOINCREMENT,
 *     event_id      VARCHAR(255) NOT NULL,
 *     user_id       VARCHAR(255) NOT NULL,
 *     code          VARCHAR(16)  NOT NULL UNIQUE,
 *     status        VARCHAR(20)  NOT NULL DEFAULT 'issued',
 *     checked_in_at DATETIME     DEFAULT NULL,
 *     cancelled_at  DATETIME     DEFAULT NULL,
 *     created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 *
 * CREATE TABLE event_capacities (
 *     event_id  VARCHAR(255) NOT NULL PRIMARY KEY,
 *     capacity  INTEGER      DEFAULT NULL,
 *     created_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class EventTicket
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── capacity management ───────────────────────────────────────────────────

    /**
     * Set the capacity for an event (null = unlimited).
     *
     * @throws \InvalidArgumentException if capacity is negative.
     */
    public function setCapacity(string $eventId, ?int $capacity): void
    {
        $eventId = $this->validateId($eventId, 'event_id');
        if ($capacity !== null && $capacity < 0) {
            throw new \InvalidArgumentException('capacity must be non-negative.');
        }

        $db     = $this->db();
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $db->prepare(
                'INSERT INTO event_capacities (event_id, capacity) VALUES (:eid, :cap)
                 ON CONFLICT (event_id) DO UPDATE SET capacity = :cap2'
            )->execute([':eid' => $eventId, ':cap' => $capacity, ':cap2' => $capacity]);
        } else {
            $db->prepare(
                'INSERT INTO event_capacities (event_id, capacity) VALUES (:eid, :cap)
                 ON DUPLICATE KEY UPDATE capacity = VALUES(capacity)'
            )->execute([':eid' => $eventId, ':cap' => $capacity]);
        }
    }

    /**
     * Get the capacity for an event, or null if not set (unlimited).
     */
    public function capacity(string $eventId): ?int
    {
        $stmt = $this->db()->prepare(
            'SELECT capacity FROM event_capacities WHERE event_id = :eid LIMIT 1'
        );
        $stmt->execute([':eid' => trim($eventId)]);
        $val = $stmt->fetchColumn();
        return $val !== false ? ($val !== null ? (int)$val : null) : null;
    }

    // ── ticket operations ─────────────────────────────────────────────────────

    /**
     * Issue a ticket to a user for an event.
     *
     * @return string  The unique ticket code (16 hex chars).
     * @throws \RuntimeException         if event is at full capacity.
     * @throws \RuntimeException         if the user already has an active ticket.
     */
    public function issue(string $eventId, string $userId): string
    {
        $eventId = $this->validateId($eventId, 'event_id');
        $userId  = $this->validateId($userId, 'user_id');

        // Check for existing active ticket
        $stmt = $this->db()->prepare(
            "SELECT COUNT(*) FROM event_tickets
             WHERE event_id = :eid AND user_id = :uid AND status = 'issued'"
        );
        $stmt->execute([':eid' => $eventId, ':uid' => $userId]);
        if ((int)$stmt->fetchColumn() > 0) {
            throw new \RuntimeException('User already has an active ticket for this event.');
        }

        // Check capacity
        if ($this->isFull($eventId)) {
            throw new \RuntimeException('Event is at full capacity.');
        }

        $code = bin2hex(random_bytes(8)); // 16 hex chars
        $this->db()->prepare(
            "INSERT INTO event_tickets (event_id, user_id, code, status)
             VALUES (:eid, :uid, :code, 'issued')"
        )->execute([':eid' => $eventId, ':uid' => $userId, ':code' => $code]);

        return $code;
    }

    /**
     * Check in a ticket by code.
     *
     * @return bool True if the ticket was found in 'issued' status and checked in.
     */
    public function checkIn(string $code): bool
    {
        $stmt = $this->db()->prepare(
            "UPDATE event_tickets
             SET status = 'checked_in', checked_in_at = CURRENT_TIMESTAMP
             WHERE code = :code AND status = 'issued'"
        );
        $stmt->execute([':code' => trim($code)]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Cancel a ticket (frees up one slot if event has a capacity limit).
     *
     * @return bool True if the ticket was found in 'issued' status and cancelled.
     */
    public function cancel(string $code): bool
    {
        $stmt = $this->db()->prepare(
            "UPDATE event_tickets
             SET status = 'cancelled', cancelled_at = CURRENT_TIMESTAMP
             WHERE code = :code AND status = 'issued'"
        );
        $stmt->execute([':code' => trim($code)]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Get ticket info by code.
     *
     * @return array<string,mixed>|null
     */
    public function findByCode(string $code): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, event_id, user_id, code, status, checked_in_at, cancelled_at, created_at
             FROM event_tickets WHERE code = :code LIMIT 1'
        );
        $stmt->execute([':code' => trim($code)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Find a user's active ticket for an event.
     *
     * @return array<string,mixed>|null
     */
    public function findByUser(string $eventId, string $userId): ?array
    {
        $stmt = $this->db()->prepare(
            "SELECT id, event_id, user_id, code, status, created_at
             FROM event_tickets
             WHERE event_id = :eid AND user_id = :uid AND status = 'issued' LIMIT 1"
        );
        $stmt->execute([':eid' => trim($eventId), ':uid' => trim($userId)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Count issued (active) tickets for an event.
     */
    public function issuedCount(string $eventId): int
    {
        $stmt = $this->db()->prepare(
            "SELECT COUNT(*) FROM event_tickets WHERE event_id = :eid AND status = 'issued'"
        );
        $stmt->execute([':eid' => trim($eventId)]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Count checked-in attendees for an event.
     */
    public function checkedInCount(string $eventId): int
    {
        $stmt = $this->db()->prepare(
            "SELECT COUNT(*) FROM event_tickets WHERE event_id = :eid AND status = 'checked_in'"
        );
        $stmt->execute([':eid' => trim($eventId)]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Check whether the event is at full capacity.
     */
    public function isFull(string $eventId): bool
    {
        $cap = $this->capacity($eventId);
        if ($cap === null) {
            return false; // unlimited
        }
        return $this->issuedCount($eventId) >= $cap;
    }

    /**
     * Get the number of remaining slots for an event.
     *
     * Returns null if the event has no capacity limit.
     */
    public function remainingSlots(string $eventId): ?int
    {
        $cap = $this->capacity($eventId);
        if ($cap === null) {
            return null;
        }
        return max(0, $cap - $this->issuedCount($eventId));
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    private function validateId(string $value, string $fieldName): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new \InvalidArgumentException("{$fieldName} must not be empty.");
        }
        return $value;
    }
}
