<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * ExpenseClaim — expense reimbursement claims with line items and approval.
 *
 * A claimant opens a draft claim, adds integer-cent line items, submits it,
 * and an approver approves/rejects; approved claims can be marked paid.
 * Distinct from `Payout` (FT291, outbound marketplace settlements),
 * `BudgetTracker` (FT, allocation/spend), and `CreditNote` (FT, refunds):
 * this is employee expense reimbursement.
 *
 * Lifecycle: `draft → submitted → approved | rejected`; `approved → paid`.
 * Line items can only be added while the claim is a draft.
 *
 * ## Usage
 *
 * ```php
 * $ec = new ExpenseClaim($pdo);
 *
 * $id = $ec->create('emp-7');
 * $ec->addItem($id, 'Taxi', 2400);
 * $ec->addItem($id, 'Lunch', 1800);
 * $ec->total($id);        // 4200
 * $ec->submit($id);       // draft → submitted
 * $ec->approve($id);      // submitted → approved
 * $ec->markPaid($id);     // approved → paid
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE expense_claims (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     claimant   VARCHAR(190) NOT NULL,
 *     status     VARCHAR(10)  NOT NULL DEFAULT 'draft',
 *     created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * CREATE TABLE expense_claim_items (
 *     id           INTEGER PRIMARY KEY AUTOINCREMENT,
 *     claim_id     BIGINT       NOT NULL,
 *     description  VARCHAR(255) NOT NULL DEFAULT '',
 *     amount_cents INTEGER      NOT NULL,
 *     created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class ExpenseClaim
{
    public const string STATUS_DRAFT     = 'draft';
    public const string STATUS_SUBMITTED = 'submitted';
    public const string STATUS_APPROVED  = 'approved';
    public const string STATUS_REJECTED  = 'rejected';
    public const string STATUS_PAID      = 'paid';

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Open a new draft claim.
     *
     * @throws \InvalidArgumentException on empty claimant.
     */
    public function create(string $claimant): int
    {
        $claimant = $this->validate($claimant, 'Claimant');
        $stmt     = $this->db()->prepare('INSERT INTO expense_claims (claimant, status) VALUES (?, ?)');
        $stmt->execute([$claimant, self::STATUS_DRAFT]);

        return (int)$this->db()->lastInsertId();
    }

    /**
     * Add a line item to a draft claim.
     *
     * @param  int    $claimId     Claim id (must be a draft).
     * @param  string $description Line description.
     * @param  int    $amountCents Line amount in cents (> 0).
     * @throws \InvalidArgumentException on non-positive amount or non-draft claim.
     */
    public function addItem(int $claimId, string $description, int $amountCents): void
    {
        if ($amountCents <= 0) {
            throw new \InvalidArgumentException('Amount must be positive.');
        }
        if ($this->status($claimId) !== self::STATUS_DRAFT) {
            throw new \InvalidArgumentException('Items can only be added to a draft claim.');
        }

        $stmt = $this->db()->prepare('INSERT INTO expense_claim_items (claim_id, description, amount_cents) VALUES (?, ?, ?)');
        $stmt->execute([$claimId, $description, $amountCents]);
    }

    /**
     * Total of a claim's line items in cents.
     */
    public function total(int $claimId): int
    {
        $stmt = $this->db()->prepare('SELECT COALESCE(SUM(amount_cents), 0) FROM expense_claim_items WHERE claim_id = ?');
        $stmt->execute([$claimId]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Line items of a claim, in insertion order.
     *
     * @return array<int,array{description:string,amount_cents:int}>
     */
    public function items(int $claimId): array
    {
        $stmt = $this->db()->prepare('SELECT description, amount_cents FROM expense_claim_items WHERE claim_id = ? ORDER BY id ASC');
        $stmt->execute([$claimId]);

        $out = [];
        /** @var array<string,mixed> $row */
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = ['description' => (string)$row['description'], 'amount_cents' => (int)$row['amount_cents']];
        }

        return $out;
    }

    /**
     * Submit a draft claim (requires at least one line item).
     *
     * @return bool True on success; false if not a draft.
     * @throws \InvalidArgumentException if the claim has no items.
     */
    public function submit(int $claimId): bool
    {
        if ($this->status($claimId) !== self::STATUS_DRAFT) {
            return false;
        }
        if ($this->total($claimId) === 0 && $this->items($claimId) === []) {
            throw new \InvalidArgumentException('Cannot submit a claim with no items.');
        }

        return $this->transition($claimId, self::STATUS_DRAFT, self::STATUS_SUBMITTED);
    }

    /**
     * Approve a submitted claim. Returns true if it was submitted.
     */
    public function approve(int $claimId): bool
    {
        return $this->transition($claimId, self::STATUS_SUBMITTED, self::STATUS_APPROVED);
    }

    /**
     * Reject a submitted claim. Returns true if it was submitted.
     */
    public function reject(int $claimId): bool
    {
        return $this->transition($claimId, self::STATUS_SUBMITTED, self::STATUS_REJECTED);
    }

    /**
     * Mark an approved claim paid. Returns true if it was approved.
     */
    public function markPaid(int $claimId): bool
    {
        return $this->transition($claimId, self::STATUS_APPROVED, self::STATUS_PAID);
    }

    /**
     * Status of a claim, or null if unknown.
     */
    public function status(int $claimId): ?string
    {
        $stmt = $this->db()->prepare('SELECT status FROM expense_claims WHERE id = ?');
        $stmt->execute([$claimId]);
        $s = $stmt->fetchColumn();

        return $s === false ? null : (string)$s;
    }

    /**
     * A claimant's claims (optionally by status) with totals, newest first.
     *
     * @return array<int,array{id:int,status:string,total:int}>
     */
    public function claimsFor(string $claimant, ?string $status = null): array
    {
        $claimant = $this->validate($claimant, 'Claimant');

        if ($status === null) {
            $stmt = $this->db()->prepare('SELECT id, status FROM expense_claims WHERE claimant = ? ORDER BY id DESC');
            $stmt->execute([$claimant]);
        } else {
            $stmt = $this->db()->prepare('SELECT id, status FROM expense_claims WHERE claimant = ? AND status = ? ORDER BY id DESC');
            $stmt->execute([$claimant, $status]);
        }

        $out = [];
        /** @var array<string,mixed> $row */
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id    = (int)$row['id'];
            $out[] = ['id' => $id, 'status' => (string)$row['status'], 'total' => $this->total($id)];
        }

        return $out;
    }

    // ── private ───────────────────────────────────────────────────────────────

    private function transition(int $claimId, string $from, string $to): bool
    {
        $stmt = $this->db()->prepare('UPDATE expense_claims SET status = ? WHERE id = ? AND status = ?');
        $stmt->execute([$to, $claimId, $from]);

        return $stmt->rowCount() === 1;
    }

    private function validate(string $value, string $label): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new \InvalidArgumentException("{$label} must not be empty.");
        }

        return $value;
    }

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
