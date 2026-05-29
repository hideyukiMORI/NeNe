<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * ChangeRequest — formal RFC / change-management approval workflow.
 *
 * Implements the classic Request For Change (RFC) pattern used in IT
 * change management (ITIL-inspired). A proposer submits a change request
 * for review; one or more reviewers approve or reject it; once approved
 * the change is implemented and then closed.
 *
 * Distinct from:
 * - `Approval` (generic single-approver approval)
 * - `ChangeLog` (append-only log of changes that already happened)
 * - `WorkflowInstance` (general-purpose stateful workflow)
 *
 * ## Usage
 *
 * ```php
 * $cr = new ChangeRequest($pdo);
 *
 * // Propose a change
 * $id = $cr->propose('user-1', 'Update DB connection pool size', 'Increase from 10 to 50.');
 *
 * // Reviewer approves
 * $cr->approve($id, 'user-2', 'Looks good.');
 *
 * // Implementer marks it done
 * $cr->implement($id);
 * $cr->close($id);
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE change_requests (
 *     id           INTEGER PRIMARY KEY AUTOINCREMENT,
 *     proposer_id  VARCHAR(255) NOT NULL,
 *     title        TEXT         NOT NULL,
 *     description  TEXT         NULL,
 *     status       VARCHAR(20)  NOT NULL DEFAULT 'draft',
 *     reviewer_id  VARCHAR(255) NULL,
 *     reviewer_note TEXT        NULL,
 *     decided_at   DATETIME     NULL,
 *     implemented_at DATETIME   NULL,
 *     created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class ChangeRequest
{
    public const STATUS_DRAFT       = 'draft';
    public const STATUS_SUBMITTED   = 'submitted';
    public const STATUS_APPROVED    = 'approved';
    public const STATUS_REJECTED    = 'rejected';
    public const STATUS_IMPLEMENTED = 'implemented';
    public const STATUS_CLOSED      = 'closed';

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Create a change request as a draft.
     *
     * @return int New change request ID.
     * @throws \InvalidArgumentException on empty proposerId or title.
     */
    public function propose(string $proposerId, string $title, ?string $description = null): int
    {
        $proposerId = trim($proposerId);
        $title      = trim($title);
        if ($proposerId === '') {
            throw new \InvalidArgumentException('proposer_id must not be empty.');
        }
        if ($title === '') {
            throw new \InvalidArgumentException('title must not be empty.');
        }

        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'INSERT INTO change_requests
                 (proposer_id, title, description, status, created_at)
             VALUES (:pid, :title, :desc, :status, :now)'
        );
        $stmt->execute([
            ':pid'    => $proposerId,
            ':title'  => $title,
            ':desc'   => $description,
            ':status' => self::STATUS_DRAFT,
            ':now'    => $now,
        ]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Retrieve a change request by ID.
     *
     * @return array<string,mixed>|null
     */
    public function get(int $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM change_requests WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Submit the change request for review (DRAFT → SUBMITTED).
     *
     * @return bool True if found and updated.
     */
    public function submit(int $id): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE change_requests SET status = :status WHERE id = :id AND status = :draft'
        );
        $stmt->execute([':status' => self::STATUS_SUBMITTED, ':id' => $id, ':draft' => self::STATUS_DRAFT]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Approve a submitted change request.
     *
     * @throws \RuntimeException if the change request is not in SUBMITTED status.
     * @return bool True if found and updated.
     */
    public function approve(int $id, string $reviewerId, ?string $note = null): bool
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'UPDATE change_requests
             SET status = :status, reviewer_id = :rid, reviewer_note = :note, decided_at = :now
             WHERE id = :id AND status = :submitted'
        );
        $stmt->execute([
            ':status'    => self::STATUS_APPROVED,
            ':rid'       => trim($reviewerId),
            ':note'      => $note,
            ':now'       => $now,
            ':id'        => $id,
            ':submitted' => self::STATUS_SUBMITTED,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Reject a submitted change request.
     *
     * @return bool True if found and updated.
     */
    public function reject(int $id, string $reviewerId, ?string $note = null): bool
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'UPDATE change_requests
             SET status = :status, reviewer_id = :rid, reviewer_note = :note, decided_at = :now
             WHERE id = :id AND status = :submitted'
        );
        $stmt->execute([
            ':status'    => self::STATUS_REJECTED,
            ':rid'       => trim($reviewerId),
            ':note'      => $note,
            ':now'       => $now,
            ':id'        => $id,
            ':submitted' => self::STATUS_SUBMITTED,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Mark an approved change request as implemented.
     *
     * @return bool True if found and updated.
     */
    public function implement(int $id): bool
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'UPDATE change_requests
             SET status = :status, implemented_at = :now
             WHERE id = :id AND status = :approved'
        );
        $stmt->execute([
            ':status'   => self::STATUS_IMPLEMENTED,
            ':now'      => $now,
            ':id'       => $id,
            ':approved' => self::STATUS_APPROVED,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Close an implemented change request.
     *
     * @return bool True if found and updated.
     */
    public function close(int $id): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE change_requests SET status = :status WHERE id = :id AND status = :implemented'
        );
        $stmt->execute([
            ':status'      => self::STATUS_CLOSED,
            ':id'          => $id,
            ':implemented' => self::STATUS_IMPLEMENTED,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * List change requests by status, newest first.
     *
     * @return list<array<string,mixed>>
     */
    public function byStatus(string $status): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM change_requests WHERE status = :status ORDER BY created_at DESC, id DESC'
        );
        $stmt->execute([':status' => $status]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * List all change requests by a proposer.
     *
     * @return list<array<string,mixed>>
     */
    public function byProposer(string $proposerId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM change_requests WHERE proposer_id = :pid ORDER BY created_at DESC, id DESC'
        );
        $stmt->execute([':pid' => trim($proposerId)]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Delete closed/rejected change requests older than $cutoff.
     *
     * @return int Number of rows deleted.
     */
    public function purgeOlderThan(string $cutoff): int
    {
        $stmt = $this->db()->prepare(
            'DELETE FROM change_requests
             WHERE status IN (:closed, :rejected) AND created_at < :cutoff'
        );
        $stmt->execute([
            ':closed'   => self::STATUS_CLOSED,
            ':rejected' => self::STATUS_REJECTED,
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
