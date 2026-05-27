<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * BudgetTracker — period-based budget allocation and spend tracking.
 *
 * Tracks allocated budgets per owner/category/period and records
 * individual spend transactions against those budgets. Useful for
 * cost-control, departmental budgeting, and campaign spend caps.
 *
 * Distinct from:
 * - `CreditLedger` (general-purpose credit/debit ledger)
 * - `PointLedger`  (loyalty point accounting)
 * - `BillingUsage` (metered usage for billing)
 *
 * ## Usage
 *
 * ```php
 * $bt = new BudgetTracker($pdo);
 *
 * // Allocate a budget
 * $id = $bt->allocate('dept-engineering', 'cloud', '2026-Q2', 500000); // $5,000.00 in cents
 *
 * // Record a spend
 * $bt->spend($id, 12000, 'AWS EC2 invoice');
 *
 * // Check remaining
 * $remaining = $bt->remaining($id); // 488000
 * $bt->isExhausted($id);            // false
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE budget_allocations (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     owner_id    VARCHAR(255) NOT NULL,
 *     category    VARCHAR(100) NOT NULL,
 *     period      VARCHAR(50)  NOT NULL,
 *     amount      INTEGER      NOT NULL,
 *     status      VARCHAR(20)  NOT NULL DEFAULT 'active',
 *     created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * CREATE TABLE budget_spends (
 *     id           INTEGER PRIMARY KEY AUTOINCREMENT,
 *     allocation_id INTEGER NOT NULL,
 *     amount        INTEGER NOT NULL,
 *     note          TEXT    NULL,
 *     spent_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class BudgetTracker
{
    public const STATUS_ACTIVE    = 'active';
    public const STATUS_EXHAUSTED = 'exhausted';
    public const STATUS_CLOSED    = 'closed';

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Allocate a budget for an owner/category/period.
     *
     * @param  int    $amount Integer cents.
     * @return int New allocation ID.
     * @throws \InvalidArgumentException on empty owner/category/period or non-positive amount.
     */
    public function allocate(string $ownerId, string $category, string $period, int $amount): int
    {
        $ownerId  = trim($ownerId);
        $category = trim($category);
        $period   = trim($period);
        if ($ownerId === '') {
            throw new \InvalidArgumentException('owner_id must not be empty.');
        }
        if ($category === '') {
            throw new \InvalidArgumentException('category must not be empty.');
        }
        if ($period === '') {
            throw new \InvalidArgumentException('period must not be empty.');
        }
        if ($amount <= 0) {
            throw new \InvalidArgumentException('amount must be positive.');
        }

        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'INSERT INTO budget_allocations (owner_id, category, period, amount, status, created_at)
             VALUES (:owner, :cat, :period, :amount, :status, :now)'
        );
        $stmt->execute([
            ':owner'  => $ownerId,
            ':cat'    => $category,
            ':period' => $period,
            ':amount' => $amount,
            ':status' => self::STATUS_ACTIVE,
            ':now'    => $now,
        ]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Retrieve an allocation by ID.
     *
     * @return array<string,mixed>|null
     */
    public function get(int $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM budget_allocations WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Record a spend transaction against an allocation.
     *
     * @return bool True on success; false if allocation not found.
     * @throws \InvalidArgumentException if amount is not positive.
     * @throws \OverflowException        if spend would exceed the budget.
     */
    public function spend(int $allocationId, int $amount, ?string $note = null): bool
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('spend amount must be positive.');
        }

        $alloc = $this->get($allocationId);
        if ($alloc === null) {
            return false;
        }

        $spent = $this->spent($allocationId);
        if ($spent + $amount > (int)$alloc['amount']) {
            throw new \OverflowException('Spend would exceed budget allocation.');
        }

        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'INSERT INTO budget_spends (allocation_id, amount, note, spent_at)
             VALUES (:aid, :amount, :note, :now)'
        );
        $stmt->execute([
            ':aid'    => $allocationId,
            ':amount' => $amount,
            ':note'   => $note,
            ':now'    => $now,
        ]);

        // Mark exhausted if fully spent
        if ($spent + $amount === (int)$alloc['amount']) {
            $this->db()->prepare(
                'UPDATE budget_allocations SET status = :status WHERE id = :id'
            )->execute([':status' => self::STATUS_EXHAUSTED, ':id' => $allocationId]);
        }

        return true;
    }

    /**
     * Total amount spent against an allocation (in cents).
     */
    public function spent(int $allocationId): int
    {
        $stmt = $this->db()->prepare(
            'SELECT COALESCE(SUM(amount), 0) FROM budget_spends WHERE allocation_id = :aid'
        );
        $stmt->execute([':aid' => $allocationId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Remaining budget for an allocation (in cents).
     * Returns 0 if allocation not found.
     */
    public function remaining(int $allocationId): int
    {
        $alloc = $this->get($allocationId);
        if ($alloc === null) {
            return 0;
        }
        return max(0, (int)$alloc['amount'] - $this->spent($allocationId));
    }

    /**
     * Whether an allocation is fully spent.
     */
    public function isExhausted(int $allocationId): bool
    {
        $alloc = $this->get($allocationId);
        if ($alloc === null) {
            return false;
        }
        return (string)$alloc['status'] === self::STATUS_EXHAUSTED
            || $this->remaining($allocationId) === 0;
    }

    /**
     * Close an allocation manually (no further spends allowed).
     *
     * @return bool True if found and updated.
     */
    public function close(int $allocationId): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE budget_allocations SET status = :status WHERE id = :id AND status = :active'
        );
        $stmt->execute([
            ':status' => self::STATUS_CLOSED,
            ':id'     => $allocationId,
            ':active' => self::STATUS_ACTIVE,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * All spend transactions for an allocation.
     *
     * @return list<array<string,mixed>>
     */
    public function spends(int $allocationId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM budget_spends WHERE allocation_id = :aid ORDER BY spent_at ASC, id ASC'
        );
        $stmt->execute([':aid' => $allocationId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * All allocations for an owner in a period.
     *
     * @return list<array<string,mixed>>
     */
    public function forOwner(string $ownerId, ?string $period = null): array
    {
        if ($period !== null) {
            $stmt = $this->db()->prepare(
                'SELECT * FROM budget_allocations WHERE owner_id = :owner AND period = :period
                 ORDER BY created_at DESC, id DESC'
            );
            $stmt->execute([':owner' => trim($ownerId), ':period' => $period]);
        } else {
            $stmt = $this->db()->prepare(
                'SELECT * FROM budget_allocations WHERE owner_id = :owner
                 ORDER BY created_at DESC, id DESC'
            );
            $stmt->execute([':owner' => trim($ownerId)]);
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * All active allocations across a period.
     *
     * @return list<array<string,mixed>>
     */
    public function activePeriod(string $period): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM budget_allocations WHERE period = :period AND status = :status
             ORDER BY owner_id ASC, category ASC'
        );
        $stmt->execute([':period' => $period, ':status' => self::STATUS_ACTIVE]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Delete all spend records and the allocation itself.
     *
     * @return bool True if the allocation existed.
     */
    public function delete(int $allocationId): bool
    {
        $alloc = $this->get($allocationId);
        if ($alloc === null) {
            return false;
        }
        $this->db()->prepare('DELETE FROM budget_spends WHERE allocation_id = :aid')
            ->execute([':aid' => $allocationId]);
        $this->db()->prepare('DELETE FROM budget_allocations WHERE id = :id')
            ->execute([':id' => $allocationId]);
        return true;
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
