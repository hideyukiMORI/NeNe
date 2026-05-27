<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * EventRsvp — event attendance management with accept/decline/maybe responses.
 *
 * Tracks per-user RSVP responses to events with optional capacity limits
 * and guest counts. Supports changing a response and attendance check-in.
 *
 * Distinct from:
 * - `EventTicket`         (paid ticket purchasing and redemption)
 * - `ResourceReservation` (time-bounded resource booking)
 * - `TimeSlot`            (appointment booking with capacity)
 *
 * ## Usage
 *
 * ```php
 * $rsvp = new EventRsvp($pdo);
 *
 * $id = $rsvp->create('evt-1', 'Annual Hackathon', '2026-06-15 09:00:00', 200);
 * $rsvp->respond($id, 'user-42', EventRsvp::RESPONSE_YES, 2);
 * $rsvp->respond($id, 'user-99', EventRsvp::RESPONSE_MAYBE);
 * $rsvp->checkin($id, 'user-42');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE event_rsvp_events (
 *     id           INTEGER PRIMARY KEY AUTOINCREMENT,
 *     event_ref    VARCHAR(255) NOT NULL,
 *     title        TEXT         NOT NULL,
 *     starts_at    DATETIME     NOT NULL,
 *     capacity     INTEGER      NULL,
 *     status       VARCHAR(20)  NOT NULL DEFAULT 'open',
 *     created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * CREATE TABLE event_rsvp_responses (
 *     id           INTEGER PRIMARY KEY AUTOINCREMENT,
 *     event_id     INTEGER      NOT NULL,
 *     user_id      VARCHAR(255) NOT NULL,
 *     response     VARCHAR(20)  NOT NULL,
 *     guests       INTEGER      NOT NULL DEFAULT 0,
 *     checked_in   INTEGER      NOT NULL DEFAULT 0,
 *     responded_at DATETIME     NOT NULL,
 *     UNIQUE (event_id, user_id)
 * );
 * ```
 */
final class EventRsvp
{
    public const STATUS_OPEN   = 'open';
    public const STATUS_CLOSED = 'closed';

    public const RESPONSE_YES   = 'yes';
    public const RESPONSE_NO    = 'no';
    public const RESPONSE_MAYBE = 'maybe';

    private const VALID_RESPONSES = [self::RESPONSE_YES, self::RESPONSE_NO, self::RESPONSE_MAYBE];

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Create an event for RSVP tracking.
     *
     * @param  int|null $capacity Maximum accepted attendees (null = unlimited).
     * @return int New event ID.
     * @throws \InvalidArgumentException on empty eventRef, title, or startsAt.
     */
    public function create(
        string $eventRef,
        string $title,
        string $startsAt,
        ?int $capacity = null
    ): int {
        $eventRef = trim($eventRef);
        $title    = trim($title);
        $startsAt = trim($startsAt);
        if ($eventRef === '') {
            throw new \InvalidArgumentException('event_ref must not be empty.');
        }
        if ($title === '') {
            throw new \InvalidArgumentException('title must not be empty.');
        }
        if ($startsAt === '') {
            throw new \InvalidArgumentException('starts_at must not be empty.');
        }

        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'INSERT INTO event_rsvp_events (event_ref, title, starts_at, capacity, status, created_at)
             VALUES (:ref, :title, :starts, :cap, :status, :now)'
        );
        $stmt->execute([
            ':ref'    => $eventRef,
            ':title'  => $title,
            ':starts' => $startsAt,
            ':cap'    => $capacity,
            ':status' => self::STATUS_OPEN,
            ':now'    => $now,
        ]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Retrieve an event by ID.
     *
     * @return array<string,mixed>|null
     */
    public function getEvent(int $eventId): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM event_rsvp_events WHERE id = :id');
        $stmt->execute([':id' => $eventId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Submit or update a user's RSVP response.
     *
     * @param  int    $guests Extra guests the user is bringing (0-based, default 0).
     * @return bool True on success; false if event not found or is closed.
     * @throws \InvalidArgumentException on invalid response or empty userId.
     */
    public function respond(int $eventId, string $userId, string $response, int $guests = 0): bool
    {
        $userId = trim($userId);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }
        if (!in_array($response, self::VALID_RESPONSES, true)) {
            throw new \InvalidArgumentException('Invalid response value.');
        }

        $event = $this->getEvent($eventId);
        if ($event === null || (string)$event['status'] !== self::STATUS_OPEN) {
            return false;
        }

        // Capacity check for YES responses
        if ($response === self::RESPONSE_YES && $event['capacity'] !== null) {
            $existing = $this->getUserResponse($eventId, $userId);
            // Don't count the current user's previous accepted count
            $previousGuests = ($existing !== null && $existing['response'] === self::RESPONSE_YES)
                ? (int)$existing['guests'] + 1
                : 0;
            $accepted = $this->acceptedCount($eventId);
            if (($accepted - $previousGuests) + $guests + 1 > (int)$event['capacity']) {
                return false; // would exceed capacity
            }
        }

        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        // Cross-driver upsert
        $driver = $this->db()->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $sql = 'INSERT INTO event_rsvp_responses (event_id, user_id, response, guests, responded_at)
                    VALUES (:eid, :uid, :resp, :guests, :now)
                    ON CONFLICT (event_id, user_id) DO UPDATE SET
                        response = excluded.response,
                        guests   = excluded.guests,
                        responded_at = excluded.responded_at';
        } else {
            $sql = 'INSERT INTO event_rsvp_responses (event_id, user_id, response, guests, responded_at)
                    VALUES (:eid, :uid, :resp, :guests, :now)
                    ON DUPLICATE KEY UPDATE
                        response = VALUES(response),
                        guests   = VALUES(guests),
                        responded_at = VALUES(responded_at)';
        }
        $this->db()->prepare($sql)->execute([
            ':eid'    => $eventId,
            ':uid'    => $userId,
            ':resp'   => $response,
            ':guests' => max(0, $guests),
            ':now'    => $now,
        ]);
        return true;
    }

    /**
     * Get a user's RSVP response for an event.
     *
     * @return array<string,mixed>|null  Null if not responded.
     */
    public function getUserResponse(int $eventId, string $userId): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM event_rsvp_responses WHERE event_id = :eid AND user_id = :uid'
        );
        $stmt->execute([':eid' => $eventId, ':uid' => trim($userId)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Check a user in to an event (mark attendance).
     *
     * @return bool True if response exists and was YES; false otherwise.
     */
    public function checkin(int $eventId, string $userId): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE event_rsvp_responses
             SET checked_in = 1
             WHERE event_id = :eid AND user_id = :uid AND response = :yes'
        );
        $stmt->execute([':eid' => $eventId, ':uid' => trim($userId), ':yes' => self::RESPONSE_YES]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Total number of accepted attendees (including guests).
     */
    public function acceptedCount(int $eventId): int
    {
        $stmt = $this->db()->prepare(
            'SELECT COALESCE(SUM(guests + 1), 0)
             FROM event_rsvp_responses
             WHERE event_id = :eid AND response = :yes'
        );
        $stmt->execute([':eid' => $eventId, ':yes' => self::RESPONSE_YES]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * All responses for an event, optionally filtered by response type.
     *
     * @return list<array<string,mixed>>
     */
    public function responses(int $eventId, ?string $response = null): array
    {
        if ($response !== null) {
            $stmt = $this->db()->prepare(
                'SELECT * FROM event_rsvp_responses WHERE event_id = :eid AND response = :resp
                 ORDER BY responded_at ASC, id ASC'
            );
            $stmt->execute([':eid' => $eventId, ':resp' => $response]);
        } else {
            $stmt = $this->db()->prepare(
                'SELECT * FROM event_rsvp_responses WHERE event_id = :eid
                 ORDER BY responded_at ASC, id ASC'
            );
            $stmt->execute([':eid' => $eventId]);
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Close an event (no more responses accepted).
     *
     * @return bool True if found and updated.
     */
    public function close(int $eventId): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE event_rsvp_events SET status = :status WHERE id = :id AND status = :open'
        );
        $stmt->execute([
            ':status' => self::STATUS_CLOSED,
            ':id'     => $eventId,
            ':open'   => self::STATUS_OPEN,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Delete an event and all its responses.
     *
     * @return bool True if the event existed.
     */
    public function delete(int $eventId): bool
    {
        $event = $this->getEvent($eventId);
        if ($event === null) {
            return false;
        }
        $this->db()->prepare('DELETE FROM event_rsvp_responses WHERE event_id = :eid')
            ->execute([':eid' => $eventId]);
        $this->db()->prepare('DELETE FROM event_rsvp_events WHERE id = :id')
            ->execute([':id' => $eventId]);
        return true;
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
