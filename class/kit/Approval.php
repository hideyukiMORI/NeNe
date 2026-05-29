<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * Approval — single-approver workflow (request → approve / reject).
 *
 * Tracks approval decisions for arbitrary resources (entity_type + entity_id).
 * One pending approval per resource at a time. The approver records their
 * decision with an optional note.
 *
 * ## Usage
 *
 * ```php
 * $appr = new Approval($pdo);
 *
 * // Request approval
 * $id = $appr->request('post', '42', 'author-1');
 *
 * // Approve or reject
 * $appr->approve($id, 'editor-1', 'Looks good');
 * $appr->reject($id, 'editor-1', 'Needs revision');
 *
 * // Query
 * $current = $appr->forResource('post', '42');
 * $pending = $appr->pending(20);
 * $history = $appr->history('post', '42');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE approvals (
 *     id            INTEGER PRIMARY KEY AUTOINCREMENT,
 *     resource_type VARCHAR(100) NOT NULL,
 *     resource_id   VARCHAR(255) NOT NULL,
 *     requester_id  VARCHAR(255) NOT NULL,
 *     approver_id   VARCHAR(255) NOT NULL DEFAULT '',
 *     status        VARCHAR(20)  NOT NULL DEFAULT 'pending',
 *     note          TEXT         NOT NULL DEFAULT '',
 *     created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     decided_at    DATETIME     NULL
 * );
 * ```
 */
final class Approval
{
    public const STATUS_PENDING  = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Submit an approval request for a resource.
     *
     * @param  string $requesterId User ID of the requester.
     * @return int    The new approval ID.
     * @throws \InvalidArgumentException on empty inputs.
     */
    public function request(string $resourceType, string $resourceId, string $requesterId): int
    {
        [$resourceType, $resourceId, $requesterId] = $this->validateInputs($resourceType, $resourceId, $requesterId);

        $stmt = $this->db()->prepare(
            'INSERT INTO approvals (resource_type, resource_id, requester_id, status)
             VALUES (:type, :id, :req, :status)'
        );
        $stmt->execute([
            ':type'   => $resourceType,
            ':id'     => $resourceId,
            ':req'    => $requesterId,
            ':status' => self::STATUS_PENDING,
        ]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Approve a pending request.
     *
     * @return bool True if the record was found and updated.
     */
    public function approve(int $approvalId, string $approverId, string $note = ''): bool
    {
        return $this->decide($approvalId, $approverId, self::STATUS_APPROVED, $note);
    }

    /**
     * Reject a pending request.
     *
     * @return bool True if the record was found and updated.
     */
    public function reject(int $approvalId, string $approverId, string $note = ''): bool
    {
        return $this->decide($approvalId, $approverId, self::STATUS_REJECTED, $note);
    }

    /**
     * Find an approval record by ID.
     *
     * @return array<string,mixed>|null
     */
    public function find(int $approvalId): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, resource_type, resource_id, requester_id, approver_id,
                    status, note, created_at, decided_at
             FROM approvals WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $approvalId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Get the most recent approval record for a resource.
     *
     * @return array<string,mixed>|null
     */
    public function forResource(string $resourceType, string $resourceId): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, resource_type, resource_id, requester_id, approver_id,
                    status, note, created_at, decided_at
             FROM approvals
             WHERE resource_type = :type AND resource_id = :id
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':type' => trim($resourceType), ':id' => trim($resourceId)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * List all pending approval requests (oldest first).
     *
     * @return list<array<string,mixed>>
     */
    public function pending(int $limit = 50): array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, resource_type, resource_id, requester_id, approver_id,
                    status, note, created_at, decided_at
             FROM approvals WHERE status = :status ORDER BY id ASC LIMIT :lim'
        );
        $stmt->bindValue(':status', self::STATUS_PENDING);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Full approval history for a resource (newest first).
     *
     * @return list<array<string,mixed>>
     */
    public function history(string $resourceType, string $resourceId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, resource_type, resource_id, requester_id, approver_id,
                    status, note, created_at, decided_at
             FROM approvals
             WHERE resource_type = :type AND resource_id = :id
             ORDER BY id DESC'
        );
        $stmt->execute([':type' => trim($resourceType), ':id' => trim($resourceId)]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Count pending approvals.
     */
    public function countPending(): int
    {
        $stmt = $this->db()->prepare('SELECT COUNT(*) FROM approvals WHERE status = :status');
        $stmt->execute([':status' => self::STATUS_PENDING]);
        return (int)$stmt->fetchColumn();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    private function decide(int $approvalId, string $approverId, string $status, string $note): bool
    {
        $approverId = trim($approverId);
        $now        = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt       = $this->db()->prepare(
            'UPDATE approvals
             SET status = :status, approver_id = :approver, note = :note, decided_at = :now
             WHERE id = :id AND status = :pending'
        );
        $stmt->execute([
            ':status'   => $status,
            ':approver' => $approverId,
            ':note'     => $note,
            ':now'      => $now,
            ':id'       => $approvalId,
            ':pending'  => self::STATUS_PENDING,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @return array{string, string, string}
     */
    private function validateInputs(string $resourceType, string $resourceId, string $requesterId): array
    {
        $resourceType = trim($resourceType);
        $resourceId   = trim($resourceId);
        $requesterId  = trim($requesterId);
        if ($resourceType === '') {
            throw new \InvalidArgumentException('resource_type must not be empty.');
        }
        if ($resourceId === '') {
            throw new \InvalidArgumentException('resource_id must not be empty.');
        }
        if ($requesterId === '') {
            throw new \InvalidArgumentException('requester_id must not be empty.');
        }
        return [$resourceType, $resourceId, $requesterId];
    }
}
