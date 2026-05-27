<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * SlaTracker — SLA/SLO breach detection for timed work items.
 *
 * Tracks service-level agreements attached to any entity (ticket, order,
 * incident, etc.). Each SLA target has a deadline; the helper detects
 * whether the SLA is still within target, breached, or paused.
 *
 * Pause / resume support models "business hours" scenarios where the clock
 * stops while a ticket is waiting for customer input. Total paused seconds
 * are accumulated so the effective elapsed time is correct.
 *
 * ## Usage
 *
 * ```php
 * $sl = new SlaTracker($pdo);
 *
 * // Start SLA when a ticket is created
 * $id = $sl->start('ticket', 'tkt-1', 'first_response', '2026-06-01 10:00:00', 4 * 3600);
 *
 * // Check breaches
 * $breaches = $sl->breached('2026-06-01 14:01:00');
 *
 * // Resolve when the SLA is met
 * $sl->resolve($id, '2026-06-01 13:59:00');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE sla_trackers (
 *     id              INTEGER PRIMARY KEY AUTOINCREMENT,
 *     entity_type     VARCHAR(100) NOT NULL,
 *     entity_id       VARCHAR(255) NOT NULL,
 *     sla_type        VARCHAR(100) NOT NULL,
 *     started_at      DATETIME     NOT NULL,
 *     deadline_at     DATETIME     NOT NULL,
 *     resolved_at     DATETIME     NULL,
 *     paused_at       DATETIME     NULL,
 *     paused_seconds  INTEGER      NOT NULL DEFAULT 0,
 *     status          VARCHAR(20)  NOT NULL DEFAULT 'active',
 *     created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class SlaTracker
{
    public const STATUS_ACTIVE   = 'active';
    public const STATUS_PAUSED   = 'paused';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_BREACHED = 'breached';

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Start tracking an SLA for an entity.
     *
     * @param int    $durationSeconds SLA window in seconds (>= 1).
     * @return int New SLA tracker ID.
     * @throws \InvalidArgumentException on empty entityType/entityId/slaType or duration < 1.
     */
    public function start(
        string $entityType,
        string $entityId,
        string $slaType,
        string $startedAt,
        int $durationSeconds
    ): int {
        $entityType = trim($entityType);
        $entityId   = trim($entityId);
        $slaType    = trim($slaType);
        if ($entityType === '') {
            throw new \InvalidArgumentException('entity_type must not be empty.');
        }
        if ($entityId === '') {
            throw new \InvalidArgumentException('entity_id must not be empty.');
        }
        if ($slaType === '') {
            throw new \InvalidArgumentException('sla_type must not be empty.');
        }
        if ($durationSeconds < 1) {
            throw new \InvalidArgumentException('duration_seconds must be >= 1.');
        }

        $deadline = (new \DateTimeImmutable($startedAt))
            ->modify("+{$durationSeconds} seconds")
            ->format('Y-m-d H:i:s');

        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'INSERT INTO sla_trackers
                 (entity_type, entity_id, sla_type, started_at, deadline_at, status, created_at)
             VALUES (:etype, :eid, :stype, :starts, :deadline, :status, :now)'
        );
        $stmt->execute([
            ':etype'    => $entityType,
            ':eid'      => $entityId,
            ':stype'    => $slaType,
            ':starts'   => $startedAt,
            ':deadline' => $deadline,
            ':status'   => self::STATUS_ACTIVE,
            ':now'      => $now,
        ]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Retrieve an SLA tracker by ID.
     *
     * @return array<string,mixed>|null
     */
    public function get(int $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM sla_trackers WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Mark the SLA as resolved (met).
     *
     * @return bool True if found and updated.
     */
    public function resolve(int $id, ?string $resolvedAt = null): bool
    {
        $at   = $resolvedAt ?? (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'UPDATE sla_trackers SET status = :status, resolved_at = :at WHERE id = :id'
        );
        $stmt->execute([':status' => self::STATUS_RESOLVED, ':at' => $at, ':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Pause the SLA clock (e.g. waiting for customer).
     *
     * No-op if already paused.
     *
     * @return bool True if found and paused.
     */
    public function pause(int $id, ?string $pausedAt = null): bool
    {
        $at   = $pausedAt ?? (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'UPDATE sla_trackers SET status = :status, paused_at = :at
             WHERE id = :id AND status = :active'
        );
        $stmt->execute([
            ':status' => self::STATUS_PAUSED,
            ':at'     => $at,
            ':id'     => $id,
            ':active' => self::STATUS_ACTIVE,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Resume the SLA clock.
     *
     * Accumulates the paused duration so breach detection stays accurate.
     *
     * @return bool True if found and resumed.
     */
    public function resume(int $id, ?string $resumedAt = null): bool
    {
        $row = $this->get($id);
        if ($row === null || $row['status'] !== self::STATUS_PAUSED) {
            return false;
        }

        $at      = $resumedAt ?? (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $paused  = new \DateTimeImmutable((string)$row['paused_at']);
        $resumed = new \DateTimeImmutable($at);
        $extra   = max(0, $resumed->getTimestamp() - $paused->getTimestamp());

        $stmt = $this->db()->prepare(
            'UPDATE sla_trackers
             SET status         = :active,
                 paused_at      = NULL,
                 paused_seconds = paused_seconds + :extra
             WHERE id = :id'
        );
        $stmt->execute([':active' => self::STATUS_ACTIVE, ':extra' => $extra, ':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Mark an SLA as breached.
     *
     * @return bool True if found and updated.
     */
    public function markBreached(int $id): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE sla_trackers SET status = :status WHERE id = :id'
        );
        $stmt->execute([':status' => self::STATUS_BREACHED, ':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Return all active SLA trackers whose deadline has passed (before $asOf).
     *
     * These are candidates for markBreached().
     *
     * @return list<array<string,mixed>>
     */
    public function breached(string $asOf): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM sla_trackers
             WHERE status = :active AND deadline_at <= :asOf
             ORDER BY deadline_at ASC, id ASC'
        );
        $stmt->execute([':active' => self::STATUS_ACTIVE, ':asOf' => $asOf]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Return all SLA trackers for an entity.
     *
     * @return list<array<string,mixed>>
     */
    public function forEntity(string $entityType, string $entityId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM sla_trackers
             WHERE entity_type = :etype AND entity_id = :eid
             ORDER BY started_at ASC, id ASC'
        );
        $stmt->execute([':etype' => trim($entityType), ':eid' => trim($entityId)]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Delete resolved/breached SLA rows older than $cutoff.
     *
     * @return int Number of rows deleted.
     */
    public function purgeOlderThan(string $cutoff): int
    {
        $stmt = $this->db()->prepare(
            'DELETE FROM sla_trackers
             WHERE status IN (:resolved, :breached) AND created_at < :cutoff'
        );
        $stmt->execute([
            ':resolved' => self::STATUS_RESOLVED,
            ':breached' => self::STATUS_BREACHED,
            ':cutoff'   => $cutoff,
        ]);
        return $stmt->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
