<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * PaymentRecord — simple payment/transaction ledger.
 *
 * Stores payment attempts and their outcomes in a lightweight DB table.
 * Not a full payment processor integration — designed as the app-side record
 * that mirrors gateway events. Amounts are stored as integer cents to avoid
 * floating-point precision issues.
 *
 * ## Usage
 *
 * ```php
 * $pr = new PaymentRecord($pdo);
 *
 * // Create a pending record
 * $id = $pr->create('ref-001', 'user-1', 1500, 'JPY', 'stripe');
 *
 * // Mark outcome after gateway response
 * $pr->markPaid($id, 'ch_abc123');
 * $pr->markFailed($id, 'card_declined');
 * $pr->markRefunded($id);
 *
 * // Query
 * $rec  = $pr->find($id);
 * $list = $pr->forUser('user-1');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE payment_records (
 *     id           INTEGER PRIMARY KEY AUTOINCREMENT,
 *     reference    VARCHAR(100) NOT NULL UNIQUE,
 *     user_id      VARCHAR(255) NOT NULL,
 *     amount_cents BIGINT       NOT NULL,
 *     currency     VARCHAR(10)  NOT NULL DEFAULT 'USD',
 *     gateway      VARCHAR(50)  NOT NULL DEFAULT '',
 *     status       VARCHAR(20)  NOT NULL DEFAULT 'pending',
 *     gateway_ref  VARCHAR(255) NOT NULL DEFAULT '',
 *     error        VARCHAR(255) NOT NULL DEFAULT '',
 *     paid_at      DATETIME     NULL,
 *     created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class PaymentRecord
{
    public const STATUS_PENDING  = 'pending';
    public const STATUS_PAID     = 'paid';
    public const STATUS_FAILED   = 'failed';
    public const STATUS_REFUNDED = 'refunded';

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Create a new pending payment record.
     *
     * @param  string $reference   Unique reference ID for the payment.
     * @param  string $userId      The payer's user ID.
     * @param  int    $amountCents Amount in minor currency units (e.g. 1500 = ¥1500 or $15.00).
     * @param  string $currency    ISO 4217 currency code (e.g. 'JPY', 'USD').
     * @param  string $gateway     Payment gateway identifier (e.g. 'stripe').
     * @return int    Row ID of the new record.
     * @throws \InvalidArgumentException on empty reference/userId or non-positive amount.
     */
    public function create(
        string $reference,
        string $userId,
        int $amountCents,
        string $currency = 'USD',
        string $gateway = ''
    ): int {
        $reference = trim($reference);
        $userId    = trim($userId);
        if ($reference === '') {
            throw new \InvalidArgumentException('reference must not be empty.');
        }
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }
        if ($amountCents <= 0) {
            throw new \InvalidArgumentException('amount_cents must be positive.');
        }

        $stmt = $this->db()->prepare(
            'INSERT INTO payment_records (reference, user_id, amount_cents, currency, gateway, status)
             VALUES (:ref, :uid, :amount, :cur, :gw, :status)'
        );
        $stmt->execute([
            ':ref'    => $reference,
            ':uid'    => $userId,
            ':amount' => $amountCents,
            ':cur'    => strtoupper($currency),
            ':gw'     => $gateway,
            ':status' => self::STATUS_PENDING,
        ]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Mark a payment as paid.
     *
     * @param  string $gatewayRef  The gateway's transaction reference.
     * @return bool True if the record was found and updated.
     */
    public function markPaid(int $id, string $gatewayRef = ''): bool
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'UPDATE payment_records
             SET status = :status, gateway_ref = :ref, paid_at = :now
             WHERE id = :id AND status = :pending'
        );
        $stmt->execute([
            ':status'  => self::STATUS_PAID,
            ':ref'     => $gatewayRef,
            ':now'     => $now,
            ':id'      => $id,
            ':pending' => self::STATUS_PENDING,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Mark a payment as failed.
     *
     * @return bool True if the record was found and updated.
     */
    public function markFailed(int $id, string $error = ''): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE payment_records
             SET status = :status, error = :error
             WHERE id = :id AND status = :pending'
        );
        $stmt->execute([
            ':status'  => self::STATUS_FAILED,
            ':error'   => $error,
            ':id'      => $id,
            ':pending' => self::STATUS_PENDING,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Mark a paid record as refunded.
     *
     * @return bool True if the record was found and updated.
     */
    public function markRefunded(int $id): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE payment_records SET status = :status WHERE id = :id AND status = :paid'
        );
        $stmt->execute([
            ':status' => self::STATUS_REFUNDED,
            ':id'     => $id,
            ':paid'   => self::STATUS_PAID,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Find a record by row ID.
     *
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, reference, user_id, amount_cents, currency, gateway,
                    status, gateway_ref, error, paid_at, created_at
             FROM payment_records WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Find a record by its unique reference string.
     *
     * @return array<string,mixed>|null
     */
    public function findByReference(string $reference): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, reference, user_id, amount_cents, currency, gateway,
                    status, gateway_ref, error, paid_at, created_at
             FROM payment_records WHERE reference = :ref LIMIT 1'
        );
        $stmt->execute([':ref' => trim($reference)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * List payment records for a user (newest first).
     *
     * @return list<array<string,mixed>>
     */
    public function forUser(string $userId, ?string $status = null, int $limit = 20): array
    {
        $sql    = 'SELECT id, reference, user_id, amount_cents, currency, gateway,
                          status, gateway_ref, error, paid_at, created_at
                   FROM payment_records WHERE user_id = :uid';
        $params = [':uid' => trim($userId)];

        if ($status !== null) {
            $sql           .= ' AND status = :status';
            $params[':status'] = $status;
        }

        $sql .= ' ORDER BY id DESC LIMIT ' . max(1, $limit);

        $stmt = $this->db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Sum paid amounts for a user (in cents).
     */
    public function totalPaid(string $userId, string $currency = ''): int
    {
        $sql    = 'SELECT COALESCE(SUM(amount_cents), 0) FROM payment_records
                   WHERE user_id = :uid AND status = :status';
        $params = [':uid' => trim($userId), ':status' => self::STATUS_PAID];

        if ($currency !== '') {
            $sql           .= ' AND currency = :cur';
            $params[':cur'] = strtoupper($currency);
        }

        $stmt = $this->db()->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
