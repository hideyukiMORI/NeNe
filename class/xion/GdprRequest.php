<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * GdprRequest — GDPR data-subject request tracking.
 *
 * Tracks Article 15 (access), Article 16 (rectification), Article 17 (erasure /
 * right-to-be-forgotten), and Article 20 (portability) requests submitted by
 * users. Each request moves through a defined lifecycle with timestamped
 * transitions.
 *
 * ## Usage
 *
 * ```php
 * $gdpr = new GdprRequest($pdo);
 *
 * // Submit a request
 * $id = $gdpr->submit('user-42', GdprRequest::TYPE_ERASURE, 'Please delete my account');
 *
 * // Workflow
 * $gdpr->acknowledge($id);
 * $gdpr->complete($id, 'All data removed from production');
 * // or $gdpr->reject($id, 'Cannot process — ongoing contract');
 *
 * // Query
 * $req    = $gdpr->find($id);
 * $list   = $gdpr->forUser('user-42');
 * $open   = $gdpr->pending(50, 0);
 * $count  = $gdpr->countByType(GdprRequest::TYPE_ERASURE);
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE gdpr_requests (
 *     id             INTEGER PRIMARY KEY AUTOINCREMENT,
 *     user_id        VARCHAR(255) NOT NULL,
 *     type           VARCHAR(30)  NOT NULL,
 *     status         VARCHAR(20)  NOT NULL DEFAULT 'pending',
 *     notes          TEXT         NULL,
 *     response_notes TEXT         NULL,
 *     submitted_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     acknowledged_at DATETIME    NULL,
 *     completed_at   DATETIME     NULL
 * );
 * ```
 */
final class GdprRequest
{
    public const TYPE_ACCESS        = 'access';
    public const TYPE_RECTIFICATION = 'rectification';
    public const TYPE_ERASURE       = 'erasure';
    public const TYPE_PORTABILITY   = 'portability';

    public const STATUS_PENDING    = 'pending';
    public const STATUS_ACKNOWLEDGED = 'acknowledged';
    public const STATUS_COMPLETED  = 'completed';
    public const STATUS_REJECTED   = 'rejected';

    private const VALID_TYPES = [
        self::TYPE_ACCESS,
        self::TYPE_RECTIFICATION,
        self::TYPE_ERASURE,
        self::TYPE_PORTABILITY,
    ];

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Submit a new GDPR request.
     *
     * @return int Row ID of the new request.
     * @throws \InvalidArgumentException on invalid userId or type.
     */
    public function submit(string $userId, string $type, ?string $notes = null): int
    {
        $userId = trim($userId);
        if ($userId === '') {
            throw new \InvalidArgumentException('userId must not be empty.');
        }
        if (!in_array($type, self::VALID_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid request type: {$type}.");
        }

        $stmt = $this->db()->prepare(
            'INSERT INTO gdpr_requests (user_id, type, status, notes)
             VALUES (:uid, :type, :status, :notes)'
        );
        $stmt->execute([
            ':uid'    => $userId,
            ':type'   => $type,
            ':status' => self::STATUS_PENDING,
            ':notes'  => $notes,
        ]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Find a single request by ID.
     *
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM gdpr_requests WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Acknowledge a pending request (confirm receipt; start processing).
     *
     * @return bool True if found in pending state and updated.
     */
    public function acknowledge(int $id): bool
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'UPDATE gdpr_requests
             SET status = :status, acknowledged_at = :now
             WHERE id = :id AND status = :pending'
        );
        $stmt->execute([
            ':status'  => self::STATUS_ACKNOWLEDGED,
            ':now'     => $now,
            ':id'      => $id,
            ':pending' => self::STATUS_PENDING,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Complete a request.
     *
     * @return bool True if found in pending|acknowledged state and updated.
     */
    public function complete(int $id, ?string $responseNotes = null): bool
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'UPDATE gdpr_requests
             SET status = :status, completed_at = :now, response_notes = :notes
             WHERE id = :id AND status IN (:pending, :ack)'
        );
        $stmt->execute([
            ':status'  => self::STATUS_COMPLETED,
            ':now'     => $now,
            ':notes'   => $responseNotes,
            ':id'      => $id,
            ':pending' => self::STATUS_PENDING,
            ':ack'     => self::STATUS_ACKNOWLEDGED,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Reject a request.
     *
     * @return bool True if found in pending|acknowledged state and updated.
     */
    public function reject(int $id, ?string $responseNotes = null): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE gdpr_requests
             SET status = :status, response_notes = :notes
             WHERE id = :id AND status IN (:pending, :ack)'
        );
        $stmt->execute([
            ':status'  => self::STATUS_REJECTED,
            ':notes'   => $responseNotes,
            ':id'      => $id,
            ':pending' => self::STATUS_PENDING,
            ':ack'     => self::STATUS_ACKNOWLEDGED,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Delete a request record permanently.
     *
     * @return bool True if found and deleted.
     */
    public function delete(int $id): bool
    {
        $stmt = $this->db()->prepare('DELETE FROM gdpr_requests WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * List all requests for a user (newest first).
     *
     * @return list<array<string,mixed>>
     */
    public function forUser(string $userId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM gdpr_requests WHERE user_id = :uid ORDER BY id DESC'
        );
        $stmt->execute([':uid' => trim($userId)]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * List pending and acknowledged requests (oldest first — FIFO processing).
     *
     * @return list<array<string,mixed>>
     */
    public function pending(int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM gdpr_requests
             WHERE status IN (:pending, :ack)
             ORDER BY id ASC
             LIMIT :lim OFFSET :off'
        );
        $stmt->bindValue(':pending', self::STATUS_PENDING);
        $stmt->bindValue(':ack', self::STATUS_ACKNOWLEDGED);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Count requests of a given type.
     *
     * @return int
     */
    public function countByType(string $type): int
    {
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM gdpr_requests WHERE type = :type'
        );
        $stmt->execute([':type' => $type]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Count requests by status.
     */
    public function countByStatus(string $status): int
    {
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM gdpr_requests WHERE status = :status'
        );
        $stmt->execute([':status' => $status]);
        return (int)$stmt->fetchColumn();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
