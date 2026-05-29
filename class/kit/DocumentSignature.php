<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * DocumentSignature — e-signature request workflow with multi-signatory support.
 *
 * Tracks document signing requests sent to one or more signatories.
 * Each signatory can sign or decline. The request is complete once all
 * required signatories have signed, or cancelled if any decline.
 *
 * Distinct from:
 * - `Approval`       (single-approver yes/no flow)
 * - `ChangeRequest`  (RFC change-management workflow)
 * - `ConsentLog`     (simple consent recording)
 *
 * ## Usage
 *
 * ```php
 * $ds = new DocumentSignature($pdo);
 *
 * $id = $ds->request('user-1', 'NDA-2026', 'NDA Agreement', ['user-2', 'user-3']);
 * $ds->sign($id, 'user-2', '192.168.1.1');
 * $ds->sign($id, 'user-3');
 * $ds->isComplete($id); // true — all signed
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE doc_signature_requests (
 *     id           INTEGER PRIMARY KEY AUTOINCREMENT,
 *     requester_id VARCHAR(255) NOT NULL,
 *     document_ref VARCHAR(255) NOT NULL,
 *     title        TEXT         NOT NULL,
 *     status       VARCHAR(20)  NOT NULL DEFAULT 'pending',
 *     created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * CREATE TABLE doc_signature_items (
 *     id           INTEGER PRIMARY KEY AUTOINCREMENT,
 *     request_id   INTEGER      NOT NULL,
 *     signatory_id VARCHAR(255) NOT NULL,
 *     status       VARCHAR(20)  NOT NULL DEFAULT 'pending',
 *     ip_address   VARCHAR(45)  NULL,
 *     signed_at    DATETIME     NULL
 * );
 * ```
 */
final class DocumentSignature
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_COMPLETE  = 'complete';
    public const STATUS_CANCELLED = 'cancelled';

    public const ITEM_PENDING  = 'pending';
    public const ITEM_SIGNED   = 'signed';
    public const ITEM_DECLINED = 'declined';

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Create a signature request with one or more signatories.
     *
     * @param  list<string> $signatories One or more user IDs required to sign.
     * @return int New request ID.
     * @throws \InvalidArgumentException on empty fields or no signatories.
     */
    public function request(
        string $requesterId,
        string $documentRef,
        string $title,
        array $signatories
    ): int {
        $requesterId = trim($requesterId);
        $documentRef = trim($documentRef);
        $title       = trim($title);
        if ($requesterId === '') {
            throw new \InvalidArgumentException('requester_id must not be empty.');
        }
        if ($documentRef === '') {
            throw new \InvalidArgumentException('document_ref must not be empty.');
        }
        if ($title === '') {
            throw new \InvalidArgumentException('title must not be empty.');
        }
        $signatories = array_values(array_filter(array_map('trim', $signatories)));
        if (count($signatories) === 0) {
            throw new \InvalidArgumentException('At least one signatory is required.');
        }

        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'INSERT INTO doc_signature_requests (requester_id, document_ref, title, status, created_at)
             VALUES (:rid, :ref, :title, :status, :now)'
        );
        $stmt->execute([
            ':rid'    => $requesterId,
            ':ref'    => $documentRef,
            ':title'  => $title,
            ':status' => self::STATUS_PENDING,
            ':now'    => $now,
        ]);
        $id = (int)$this->db()->lastInsertId();

        $itemStmt = $this->db()->prepare(
            'INSERT INTO doc_signature_items (request_id, signatory_id, status)
             VALUES (:rid, :sig, :status)'
        );
        foreach ($signatories as $signatoryId) {
            $itemStmt->execute([
                ':rid'    => $id,
                ':sig'    => $signatoryId,
                ':status' => self::ITEM_PENDING,
            ]);
        }
        return $id;
    }

    /**
     * Retrieve a signature request by ID.
     *
     * @return array<string,mixed>|null
     */
    public function get(int $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM doc_signature_requests WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Record a signatory's signature.
     *
     * If all signatories have now signed, the request is marked COMPLETE.
     *
     * @return bool True on success; false if no pending item found.
     */
    public function sign(int $requestId, string $signatoryId, ?string $ipAddress = null): bool
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'UPDATE doc_signature_items
             SET status = :signed, ip_address = :ip, signed_at = :now
             WHERE request_id = :rid AND signatory_id = :sig AND status = :pending'
        );
        $stmt->execute([
            ':signed'  => self::ITEM_SIGNED,
            ':ip'      => $ipAddress,
            ':now'     => $now,
            ':rid'     => $requestId,
            ':sig'     => trim($signatoryId),
            ':pending' => self::ITEM_PENDING,
        ]);
        if ($stmt->rowCount() === 0) {
            return false;
        }

        // Check if all items are now signed
        $pending = $this->pendingCount($requestId);
        if ($pending === 0) {
            $this->db()->prepare(
                'UPDATE doc_signature_requests SET status = :status WHERE id = :id'
            )->execute([':status' => self::STATUS_COMPLETE, ':id' => $requestId]);
        }
        return true;
    }

    /**
     * Record a signatory's decline; cancels the whole request.
     *
     * @return bool True on success; false if no pending item found.
     */
    public function decline(int $requestId, string $signatoryId): bool
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'UPDATE doc_signature_items
             SET status = :declined, signed_at = :now
             WHERE request_id = :rid AND signatory_id = :sig AND status = :pending'
        );
        $stmt->execute([
            ':declined' => self::ITEM_DECLINED,
            ':now'      => $now,
            ':rid'      => $requestId,
            ':sig'      => trim($signatoryId),
            ':pending'  => self::ITEM_PENDING,
        ]);
        if ($stmt->rowCount() === 0) {
            return false;
        }

        // Cancel the whole request
        $this->db()->prepare(
            'UPDATE doc_signature_requests SET status = :status WHERE id = :id'
        )->execute([':status' => self::STATUS_CANCELLED, ':id' => $requestId]);
        return true;
    }

    /**
     * Whether all signatories have signed.
     */
    public function isComplete(int $requestId): bool
    {
        $req = $this->get($requestId);
        return $req !== null && (string)$req['status'] === self::STATUS_COMPLETE;
    }

    /**
     * Number of items still awaiting signature.
     */
    public function pendingCount(int $requestId): int
    {
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM doc_signature_items
             WHERE request_id = :rid AND status = :pending'
        );
        $stmt->execute([':rid' => $requestId, ':pending' => self::ITEM_PENDING]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * All signatory items for a request.
     *
     * @return list<array<string,mixed>>
     */
    public function items(int $requestId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM doc_signature_items WHERE request_id = :rid ORDER BY id ASC'
        );
        $stmt->execute([':rid' => $requestId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * All signature requests for a document reference.
     *
     * @return list<array<string,mixed>>
     */
    public function forDocument(string $documentRef): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM doc_signature_requests WHERE document_ref = :ref
             ORDER BY created_at DESC, id DESC'
        );
        $stmt->execute([':ref' => trim($documentRef)]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * All pending requests for a given signatory.
     *
     * @return list<array<string,mixed>>
     */
    public function pendingFor(string $signatoryId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT r.* FROM doc_signature_requests r
             JOIN doc_signature_items i ON i.request_id = r.id
             WHERE i.signatory_id = :sig AND i.status = :pending AND r.status = :req_pending
             ORDER BY r.created_at ASC, r.id ASC'
        );
        $stmt->execute([
            ':sig'         => trim($signatoryId),
            ':pending'     => self::ITEM_PENDING,
            ':req_pending' => self::STATUS_PENDING,
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Cancel a pending request manually.
     *
     * @return bool True if found and pending.
     */
    public function cancel(int $requestId): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE doc_signature_requests SET status = :status WHERE id = :id AND status = :pending'
        );
        $stmt->execute([
            ':status'  => self::STATUS_CANCELLED,
            ':id'      => $requestId,
            ':pending' => self::STATUS_PENDING,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Delete a request and all signatory items.
     *
     * @return bool True if the request existed.
     */
    public function delete(int $requestId): bool
    {
        $req = $this->get($requestId);
        if ($req === null) {
            return false;
        }
        $this->db()->prepare('DELETE FROM doc_signature_items WHERE request_id = :rid')
            ->execute([':rid' => $requestId]);
        $this->db()->prepare('DELETE FROM doc_signature_requests WHERE id = :id')
            ->execute([':id' => $requestId]);
        return true;
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
