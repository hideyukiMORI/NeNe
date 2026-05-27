<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * ResourceReservation — time-bounded reservation of a shared resource.
 *
 * Manages bookings for shared physical or logical resources (meeting rooms,
 * equipment, parking spots, demo environments, etc.). Includes overlap
 * detection so two users cannot hold the same resource for the same period.
 *
 * ## Usage
 *
 * ```php
 * $rr = new ResourceReservation($pdo);
 *
 * // Check availability before booking
 * if ($rr->isAvailable('room-A', '2026-06-01 10:00:00', '2026-06-01 11:00:00')) {
 *     $id = $rr->reserve('room-A', 'user-1', '2026-06-01 10:00:00', '2026-06-01 11:00:00');
 * }
 *
 * // List upcoming bookings for a resource
 * $upcoming = $rr->upcoming('room-A');
 *
 * // Cancel / complete
 * $rr->cancel($id);
 * $rr->complete($id);
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE resource_reservations (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     resource_id VARCHAR(255) NOT NULL,
 *     user_id     VARCHAR(255) NOT NULL,
 *     starts_at   DATETIME     NOT NULL,
 *     ends_at     DATETIME     NOT NULL,
 *     status      VARCHAR(20)  NOT NULL DEFAULT 'confirmed',
 *     notes       TEXT         NULL,
 *     created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class ResourceReservation
{
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_COMPLETED = 'completed';

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Reserve a resource for a time window.
     *
     * Throws \RuntimeException if the resource is already booked for the
     * requested window (status = confirmed overlap).
     *
     * @return int New reservation ID.
     * @throws \InvalidArgumentException on empty resourceId/userId or invalid window.
     * @throws \RuntimeException if the resource is already reserved for the period.
     */
    public function reserve(
        string $resourceId,
        string $userId,
        string $startsAt,
        string $endsAt,
        ?string $notes = null
    ): int {
        $resourceId = trim($resourceId);
        $userId     = trim($userId);
        if ($resourceId === '') {
            throw new \InvalidArgumentException('resource_id must not be empty.');
        }
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }
        if ($startsAt >= $endsAt) {
            throw new \InvalidArgumentException('starts_at must be before ends_at.');
        }

        if (!$this->isAvailable($resourceId, $startsAt, $endsAt)) {
            throw new \RuntimeException('Resource is already reserved for the requested period.');
        }

        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'INSERT INTO resource_reservations
                 (resource_id, user_id, starts_at, ends_at, status, notes, created_at)
             VALUES (:rid, :uid, :starts, :ends, :status, :notes, :now)'
        );
        $stmt->execute([
            ':rid'    => $resourceId,
            ':uid'    => $userId,
            ':starts' => $startsAt,
            ':ends'   => $endsAt,
            ':status' => self::STATUS_CONFIRMED,
            ':notes'  => $notes,
            ':now'    => $now,
        ]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Retrieve a reservation by ID.
     *
     * @return array<string,mixed>|null
     */
    public function get(int $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM resource_reservations WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Check whether a resource is free for the given window.
     *
     * An optional $excludeId skips one reservation row (useful for re-booking
     * the same slot).
     */
    public function isAvailable(string $resourceId, string $startsAt, string $endsAt, int $excludeId = 0): bool
    {
        $sql = 'SELECT COUNT(*) FROM resource_reservations
                WHERE resource_id = :rid
                  AND status = :status
                  AND starts_at < :ends
                  AND ends_at   > :starts';
        if ($excludeId > 0) {
            $sql .= ' AND id != :excl';
        }
        $stmt = $this->db()->prepare($sql);
        $params = [
            ':rid'    => trim($resourceId),
            ':status' => self::STATUS_CONFIRMED,
            ':starts' => $startsAt,
            ':ends'   => $endsAt,
        ];
        if ($excludeId > 0) {
            $params[':excl'] = $excludeId;
        }
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() === 0;
    }

    /**
     * Cancel a reservation.
     *
     * @return bool True if found and updated.
     */
    public function cancel(int $id): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE resource_reservations SET status = :status WHERE id = :id'
        );
        $stmt->execute([':status' => self::STATUS_CANCELLED, ':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Mark a reservation as completed.
     *
     * @return bool True if found and updated.
     */
    public function complete(int $id): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE resource_reservations SET status = :status WHERE id = :id'
        );
        $stmt->execute([':status' => self::STATUS_COMPLETED, ':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * List confirmed reservations for a resource within a time range.
     *
     * Returns rows where starts_at < $to AND ends_at > $from (overlapping range).
     *
     * @return list<array<string,mixed>>
     */
    public function forResource(string $resourceId, string $from, string $to): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM resource_reservations
             WHERE resource_id = :rid
               AND status = :status
               AND starts_at < :to
               AND ends_at   > :from
             ORDER BY starts_at ASC, id ASC'
        );
        $stmt->execute([
            ':rid'    => trim($resourceId),
            ':status' => self::STATUS_CONFIRMED,
            ':from'   => $from,
            ':to'     => $to,
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * List all reservations for a user (all statuses), newest first.
     *
     * @return list<array<string,mixed>>
     */
    public function forUser(string $userId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM resource_reservations
             WHERE user_id = :uid
             ORDER BY starts_at DESC, id DESC'
        );
        $stmt->execute([':uid' => trim($userId)]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * List upcoming confirmed reservations for a resource (starts_at >= now).
     *
     * @return list<array<string,mixed>>
     */
    public function upcoming(string $resourceId): array
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'SELECT * FROM resource_reservations
             WHERE resource_id = :rid
               AND status = :status
               AND starts_at >= :now
             ORDER BY starts_at ASC, id ASC'
        );
        $stmt->execute([
            ':rid'    => trim($resourceId),
            ':status' => self::STATUS_CONFIRMED,
            ':now'    => $now,
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Delete cancelled/completed reservations older than $cutoff.
     *
     * @return int Number of rows deleted.
     */
    public function purgeOlderThan(string $cutoff): int
    {
        $stmt = $this->db()->prepare(
            'DELETE FROM resource_reservations
             WHERE status != :confirmed AND created_at < :cutoff'
        );
        $stmt->execute([':confirmed' => self::STATUS_CONFIRMED, ':cutoff' => $cutoff]);
        return $stmt->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
