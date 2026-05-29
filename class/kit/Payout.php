<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * Payout — accrue amounts owed to payees and settle them in payout runs.
 *
 * Tracks money owed to sellers / affiliates / creators as individual
 * integer-cent line items, then settles a payee's outstanding balance in a
 * payout run. Distinct from `PaymentRecord` (incoming customer payments) and
 * `CreditLedger` (general double-entry): this is the *outbound* marketplace
 * payout side.
 *
 * Each accrual is an immutable line with a status: `pending` → `paid`
 * (settled in a run) or `failed` (e.g. transfer bounced; can be retried by
 * re-accruing or reopening out of band).
 *
 * ## Usage
 *
 * ```php
 * $p = new Payout($pdo);
 *
 * $p->accrue('seller-7', 1500, 'order-100');  // owe $15.00
 * $p->accrue('seller-7', 800,  'order-101');  // owe $8.00
 *
 * $p->pendingTotal('seller-7');   // 2300
 * $paid = $p->pay('seller-7');    // settle pending → 2300, lines marked paid
 * $p->pendingTotal('seller-7');   // 0
 * $p->paidTotal('seller-7');      // 2300
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE payouts (
 *     id           INTEGER PRIMARY KEY AUTOINCREMENT,
 *     payee        VARCHAR(190) NOT NULL,
 *     amount_cents INTEGER      NOT NULL,
 *     status       VARCHAR(10)  NOT NULL DEFAULT 'pending',
 *     reference    VARCHAR(190) NOT NULL DEFAULT '',
 *     created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     paid_at      DATETIME     NULL
 * );
 * ```
 */
final class Payout
{
    public const string STATUS_PENDING = 'pending';
    public const string STATUS_PAID    = 'paid';
    public const string STATUS_FAILED  = 'failed';

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Accrue an amount owed to a payee (a pending line item).
     *
     * @param  string $payee      Payee identifier.
     * @param  int    $amountCents Amount owed in cents (> 0).
     * @param  string $reference  Optional source reference (order id, etc.).
     * @return int                New line item id.
     * @throws \InvalidArgumentException on empty payee or non-positive amount.
     */
    public function accrue(string $payee, int $amountCents, string $reference = ''): int
    {
        $payee = $this->validate($payee, 'Payee');
        if ($amountCents <= 0) {
            throw new \InvalidArgumentException('Amount must be positive.');
        }

        $stmt = $this->db()->prepare(
            'INSERT INTO payouts (payee, amount_cents, status, reference) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$payee, $amountCents, self::STATUS_PENDING, $reference]);

        return (int)$this->db()->lastInsertId();
    }

    /**
     * Total pending (unsettled) cents owed to a payee.
     */
    public function pendingTotal(string $payee): int
    {
        return $this->sum($payee, self::STATUS_PENDING);
    }

    /**
     * Total settled cents paid to a payee.
     */
    public function paidTotal(string $payee): int
    {
        return $this->sum($payee, self::STATUS_PAID);
    }

    /**
     * Settle a payee's pending balance: mark all pending lines paid.
     *
     * @param  string      $payee Payee identifier.
     * @param  string|null $asOf  Settlement time; defaults to now.
     * @return int                Total cents settled in this run (0 if nothing pending).
     */
    public function pay(string $payee, ?string $asOf = null): int
    {
        $payee = $this->validate($payee, 'Payee');
        $total = $this->pendingTotal($payee);
        if ($total === 0) {
            return 0;
        }

        $stmt = $this->db()->prepare(
            'UPDATE payouts SET status = ?, paid_at = ? WHERE payee = ? AND status = ?'
        );
        $stmt->execute([self::STATUS_PAID, $this->ts($asOf), $payee, self::STATUS_PENDING]);

        return $total;
    }

    /**
     * Mark a single line item failed. Returns true if it was pending.
     */
    public function markFailed(int $id): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE payouts SET status = ? WHERE id = ? AND status = ?'
        );
        $stmt->execute([self::STATUS_FAILED, $id, self::STATUS_PENDING]);

        return $stmt->rowCount() === 1;
    }

    /**
     * List a payee's line items (optionally filtered by status), newest first.
     *
     * @param  string      $payee  Payee identifier.
     * @param  string|null $status One of the STATUS_* constants, or null for all.
     * @return array<int,array{id:int,amount_cents:int,status:string,reference:string}>
     * @throws \InvalidArgumentException on unknown status.
     */
    public function items(string $payee, ?string $status = null): array
    {
        $payee = $this->validate($payee, 'Payee');
        if ($status !== null && !in_array($status, [self::STATUS_PENDING, self::STATUS_PAID, self::STATUS_FAILED], true)) {
            throw new \InvalidArgumentException("Unknown status: {$status}");
        }

        if ($status === null) {
            $stmt = $this->db()->prepare(
                'SELECT id, amount_cents, status, reference FROM payouts WHERE payee = ? ORDER BY id DESC'
            );
            $stmt->execute([$payee]);
        } else {
            $stmt = $this->db()->prepare(
                'SELECT id, amount_cents, status, reference FROM payouts WHERE payee = ? AND status = ? ORDER BY id DESC'
            );
            $stmt->execute([$payee, $status]);
        }

        $out = [];
        /** @var array<string,mixed> $row */
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = [
                'id'           => (int)$row['id'],
                'amount_cents' => (int)$row['amount_cents'],
                'status'       => (string)$row['status'],
                'reference'    => (string)$row['reference'],
            ];
        }

        return $out;
    }

    // ── private ───────────────────────────────────────────────────────────────

    private function sum(string $payee, string $status): int
    {
        $payee = $this->validate($payee, 'Payee');
        $stmt  = $this->db()->prepare(
            'SELECT COALESCE(SUM(amount_cents), 0) FROM payouts WHERE payee = ? AND status = ?'
        );
        $stmt->execute([$payee, $status]);

        return (int)$stmt->fetchColumn();
    }

    private function validate(string $value, string $label): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new \InvalidArgumentException("{$label} must not be empty.");
        }

        return $value;
    }

    private function ts(?string $asOf): string
    {
        $epoch = strtotime($asOf ?? 'now');
        if ($epoch === false) {
            throw new \InvalidArgumentException('Invalid timestamp.');
        }

        return date('Y-m-d H:i:s', $epoch);
    }

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
