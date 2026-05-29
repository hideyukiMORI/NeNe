<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * CreditNote — financial credit notes / refund adjustments.
 *
 * Tracks credit notes issued against an entity (order, invoice, account, etc.).
 * Amount is stored in the smallest currency unit (cents / yen) to avoid
 * floating-point rounding. A credit note starts as PENDING, transitions to
 * APPLIED when the credit has been consumed, or VOIDED if it is cancelled.
 *
 * ## Usage
 *
 * ```php
 * $cn = new CreditNote($pdo);
 *
 * // Issue a credit note for 10 USD against an order
 * $id = $cn->issue('order', 'ord-1', 1000, 'Damaged item refund');
 *
 * // Apply when the credit is consumed
 * $cn->apply($id);
 *
 * // Query
 * $pending = $cn->pendingTotal('order', 'ord-1');  // sum of pending cents
 * $notes   = $cn->forEntity('order', 'ord-1');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE credit_notes (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     reference   VARCHAR(100) NOT NULL DEFAULT '',
 *     entity_type VARCHAR(100) NOT NULL,
 *     entity_id   VARCHAR(255) NOT NULL,
 *     amount      INTEGER      NOT NULL,
 *     reason      TEXT         NULL,
 *     status      VARCHAR(20)  NOT NULL DEFAULT 'pending',
 *     issued_at   DATETIME     NOT NULL,
 *     applied_at  DATETIME     NULL,
 *     created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class CreditNote
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_VOIDED  = 'voided';

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Issue a credit note against an entity.
     *
     * @param int    $amountCents Amount in smallest currency unit (must be > 0).
     * @param string $reference   Optional external reference number.
     * @return int New credit note ID.
     * @throws \InvalidArgumentException on empty entityType/entityId or amount <= 0.
     */
    public function issue(
        string $entityType,
        string $entityId,
        int $amountCents,
        ?string $reason = null,
        string $reference = ''
    ): int {
        $entityType = trim($entityType);
        $entityId   = trim($entityId);
        if ($entityType === '') {
            throw new \InvalidArgumentException('entity_type must not be empty.');
        }
        if ($entityId === '') {
            throw new \InvalidArgumentException('entity_id must not be empty.');
        }
        if ($amountCents <= 0) {
            throw new \InvalidArgumentException('amount must be > 0.');
        }

        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'INSERT INTO credit_notes
                 (reference, entity_type, entity_id, amount, reason, status, issued_at, created_at)
             VALUES (:ref, :etype, :eid, :amount, :reason, :status, :now, :now2)'
        );
        $stmt->execute([
            ':ref'    => $reference,
            ':etype'  => $entityType,
            ':eid'    => $entityId,
            ':amount' => $amountCents,
            ':reason' => $reason,
            ':status' => self::STATUS_PENDING,
            ':now'    => $now,
            ':now2'   => $now,
        ]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Retrieve a credit note by ID.
     *
     * @return array<string,mixed>|null
     */
    public function get(int $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM credit_notes WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Mark a credit note as applied (credit consumed).
     *
     * @return bool True if found and updated.
     */
    public function apply(int $id): bool
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'UPDATE credit_notes SET status = :status, applied_at = :now WHERE id = :id'
        );
        $stmt->execute([':status' => self::STATUS_APPLIED, ':now' => $now, ':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Void a credit note (cancel without applying).
     *
     * @return bool True if found and updated.
     */
    public function void(int $id): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE credit_notes SET status = :status WHERE id = :id'
        );
        $stmt->execute([':status' => self::STATUS_VOIDED, ':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * List all credit notes for an entity (newest first).
     *
     * @return list<array<string,mixed>>
     */
    public function forEntity(string $entityType, string $entityId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM credit_notes
             WHERE entity_type = :etype AND entity_id = :eid
             ORDER BY issued_at DESC, id DESC'
        );
        $stmt->execute([':etype' => trim($entityType), ':eid' => trim($entityId)]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Sum of all credit notes in a given status for an entity.
     *
     * Returns 0 if none exist.
     */
    public function total(string $entityType, string $entityId, string $status = self::STATUS_PENDING): int
    {
        $stmt = $this->db()->prepare(
            'SELECT COALESCE(SUM(amount), 0) FROM credit_notes
             WHERE entity_type = :etype AND entity_id = :eid AND status = :status'
        );
        $stmt->execute([':etype' => trim($entityType), ':eid' => trim($entityId), ':status' => $status]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Sum of pending credit notes for an entity (convenience wrapper).
     */
    public function pendingTotal(string $entityType, string $entityId): int
    {
        return $this->total($entityType, $entityId, self::STATUS_PENDING);
    }

    /**
     * Delete voided/applied credit notes older than $cutoff.
     *
     * @return int Number of rows deleted.
     */
    public function purgeOlderThan(string $cutoff): int
    {
        $stmt = $this->db()->prepare(
            'DELETE FROM credit_notes WHERE status != :pending AND created_at < :cutoff'
        );
        $stmt->execute([':pending' => self::STATUS_PENDING, ':cutoff' => $cutoff]);
        return $stmt->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
