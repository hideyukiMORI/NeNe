<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * IncidentLog — IT/service incident tracking with severity and lifecycle.
 *
 * Tracks service incidents from detection through resolution. Incidents
 * have a severity level (P1–P4) and a lifecycle: OPEN → INVESTIGATING →
 * RESOLVED → CLOSED. Timeline events are appended as the incident
 * progresses so post-mortem analysis is possible.
 *
 * ## Usage
 *
 * ```php
 * $il = new IncidentLog($pdo);
 *
 * // Open a P1 incident
 * $id = $il->open('Payment service outage', IncidentLog::SEVERITY_P1);
 *
 * // Transition through lifecycle
 * $il->investigate($id);
 * $il->addEvent($id, 'Root cause identified: DB connection pool exhausted.');
 * $il->resolve($id, 'Increased connection pool size; service restored.');
 * $il->close($id);
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE incident_logs (
 *     id           INTEGER PRIMARY KEY AUTOINCREMENT,
 *     title        TEXT         NOT NULL,
 *     severity     VARCHAR(10)  NOT NULL DEFAULT 'P3',
 *     status       VARCHAR(20)  NOT NULL DEFAULT 'open',
 *     description  TEXT         NULL,
 *     resolution   TEXT         NULL,
 *     opened_at    DATETIME     NOT NULL,
 *     resolved_at  DATETIME     NULL,
 *     created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 *
 * CREATE TABLE incident_events (
 *     id           INTEGER PRIMARY KEY AUTOINCREMENT,
 *     incident_id  INTEGER      NOT NULL,
 *     message      TEXT         NOT NULL,
 *     created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class IncidentLog
{
    public const SEVERITY_P1 = 'P1';
    public const SEVERITY_P2 = 'P2';
    public const SEVERITY_P3 = 'P3';
    public const SEVERITY_P4 = 'P4';

    public const STATUS_OPEN          = 'open';
    public const STATUS_INVESTIGATING = 'investigating';
    public const STATUS_RESOLVED      = 'resolved';
    public const STATUS_CLOSED        = 'closed';

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Open a new incident.
     *
     * @return int New incident ID.
     * @throws \InvalidArgumentException on empty title or invalid severity.
     */
    public function open(string $title, string $severity = self::SEVERITY_P3, ?string $description = null): int
    {
        $title = trim($title);
        if ($title === '') {
            throw new \InvalidArgumentException('title must not be empty.');
        }
        if (!in_array($severity, [self::SEVERITY_P1, self::SEVERITY_P2, self::SEVERITY_P3, self::SEVERITY_P4], true)) {
            throw new \InvalidArgumentException('severity must be P1, P2, P3, or P4.');
        }

        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'INSERT INTO incident_logs
                 (title, severity, status, description, opened_at, created_at)
             VALUES (:title, :sev, :status, :desc, :now, :now2)'
        );
        $stmt->execute([
            ':title'  => $title,
            ':sev'    => $severity,
            ':status' => self::STATUS_OPEN,
            ':desc'   => $description,
            ':now'    => $now,
            ':now2'   => $now,
        ]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Retrieve an incident by ID.
     *
     * @return array<string,mixed>|null
     */
    public function get(int $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM incident_logs WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Transition an incident to INVESTIGATING.
     *
     * @return bool True if found and updated.
     */
    public function investigate(int $id): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE incident_logs SET status = :status WHERE id = :id'
        );
        $stmt->execute([':status' => self::STATUS_INVESTIGATING, ':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Resolve an incident with an optional resolution note.
     *
     * @return bool True if found and updated.
     */
    public function resolve(int $id, ?string $resolution = null): bool
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'UPDATE incident_logs
             SET status = :status, resolution = :resolution, resolved_at = :now
             WHERE id = :id'
        );
        $stmt->execute([
            ':status'     => self::STATUS_RESOLVED,
            ':resolution' => $resolution,
            ':now'        => $now,
            ':id'         => $id,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Close a resolved incident (post-mortem complete).
     *
     * @return bool True if found and updated.
     */
    public function close(int $id): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE incident_logs SET status = :status WHERE id = :id'
        );
        $stmt->execute([':status' => self::STATUS_CLOSED, ':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Update the severity of an open incident.
     *
     * @return bool True if found and updated.
     * @throws \InvalidArgumentException on invalid severity.
     */
    public function updateSeverity(int $id, string $severity): bool
    {
        if (!in_array($severity, [self::SEVERITY_P1, self::SEVERITY_P2, self::SEVERITY_P3, self::SEVERITY_P4], true)) {
            throw new \InvalidArgumentException('severity must be P1, P2, P3, or P4.');
        }
        $stmt = $this->db()->prepare('UPDATE incident_logs SET severity = :sev WHERE id = :id');
        $stmt->execute([':sev' => $severity, ':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Append a timeline event message to an incident.
     *
     * @return int New event ID.
     * @throws \InvalidArgumentException on empty message.
     */
    public function addEvent(int $incidentId, string $message): int
    {
        $message = trim($message);
        if ($message === '') {
            throw new \InvalidArgumentException('message must not be empty.');
        }
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'INSERT INTO incident_events (incident_id, message, created_at) VALUES (:iid, :msg, :now)'
        );
        $stmt->execute([':iid' => $incidentId, ':msg' => $message, ':now' => $now]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Return all timeline events for an incident, oldest first.
     *
     * @return list<array<string,mixed>>
     */
    public function events(int $incidentId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM incident_events WHERE incident_id = :iid ORDER BY created_at ASC, id ASC'
        );
        $stmt->execute([':iid' => $incidentId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * List open/investigating incidents, most severe first (P1 before P4).
     *
     * @return list<array<string,mixed>>
     */
    public function listOpen(): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM incident_logs
             WHERE status IN (:open, :investigating)
             ORDER BY severity ASC, opened_at ASC, id ASC'
        );
        $stmt->execute([':open' => self::STATUS_OPEN, ':investigating' => self::STATUS_INVESTIGATING]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * List incidents by severity.
     *
     * @return list<array<string,mixed>>
     */
    public function bySeverity(string $severity): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM incident_logs WHERE severity = :sev ORDER BY opened_at DESC, id DESC'
        );
        $stmt->execute([':sev' => $severity]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Delete closed incidents older than $cutoff.
     *
     * @return int Number of rows deleted.
     */
    public function purgeOlderThan(string $cutoff): int
    {
        // Find IDs to purge first so we can delete events too
        $stmt = $this->db()->prepare(
            'SELECT id FROM incident_logs WHERE status = :closed AND created_at < :cutoff'
        );
        $stmt->execute([':closed' => self::STATUS_CLOSED, ':cutoff' => $cutoff]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (empty($ids)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $del = $this->db()->prepare("DELETE FROM incident_events WHERE incident_id IN ({$placeholders})");
        $del->execute($ids);

        $del = $this->db()->prepare("DELETE FROM incident_logs WHERE id IN ({$placeholders})");
        $del->execute($ids);
        return $del->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
