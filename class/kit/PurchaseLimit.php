<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\DbUpsert;
use Nene\Xion\PdoConnection;
use PDO;

/**
 * PurchaseLimit — per-user purchase caps per SKU over a rolling window.
 *
 * Enforces "max N of this item per user per P days" rules (limited drops,
 * fair-allocation sales, regulated goods). A policy table holds the cap per
 * SKU; a records table logs purchases; `canPurchase()` checks the rolling
 * window. Distinct from `Quota` (FT166, plan resource quotas) and
 * `StorageQuota`: this is per-user, per-SKU purchase frequency.
 *
 * Integer quantities; `asOf` makes the rolling window deterministic in tests.
 *
 * ## Usage
 *
 * ```php
 * $pl = new PurchaseLimit($pdo);
 *
 * $pl->setLimit('sku-1', maxQty: 2, periodDays: 30);  // 2 per 30 days
 *
 * $pl->canPurchase('sku-1', userId: 7, qty: 2);  // true
 * $pl->record('sku-1', 7, 2);                    // they buy 2
 * $pl->remaining('sku-1', 7);                    // 0
 * $pl->canPurchase('sku-1', 7, 1);               // false (cap reached)
 *
 * $pl->canPurchase('unlimited-sku', 7, 99);      // true (no policy = unlimited)
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE purchase_limit_policies (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     sku         VARCHAR(150) NOT NULL,
 *     max_qty     INTEGER      NOT NULL,
 *     period_days INTEGER      NOT NULL,
 *     UNIQUE (sku)
 * );
 * CREATE TABLE purchase_limit_records (
 *     id           INTEGER PRIMARY KEY AUTOINCREMENT,
 *     sku          VARCHAR(150) NOT NULL,
 *     user_id      BIGINT       NOT NULL,
 *     qty          INTEGER      NOT NULL,
 *     purchased_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class PurchaseLimit
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── policy ──────────────────────────────────────────────────────────────────

    /**
     * Set (or replace) the purchase cap for a SKU. Idempotent per SKU.
     *
     * @param  string $sku        Product identifier.
     * @param  int    $maxQty     Max units per user per window (>= 1).
     * @param  int    $periodDays Rolling window length in days (>= 1).
     * @throws \InvalidArgumentException on empty SKU or non-positive bounds.
     */
    public function setLimit(string $sku, int $maxQty, int $periodDays): void
    {
        $sku = $this->validate($sku, 'SKU');
        if ($maxQty < 1 || $periodDays < 1) {
            throw new \InvalidArgumentException('Max quantity and period must be at least 1.');
        }

        DbUpsert::run(
            $this->db(),
            table:        'purchase_limit_policies',
            data:         ['sku' => $sku, 'max_qty' => $maxQty, 'period_days' => $periodDays],
            conflictCols: ['sku'],
            updateCols:   ['max_qty', 'period_days'],
        );
    }

    /**
     * Remove a SKU's cap (makes it unlimited). No-op if absent.
     */
    public function removeLimit(string $sku): void
    {
        $sku  = $this->validate($sku, 'SKU');
        $stmt = $this->db()->prepare('DELETE FROM purchase_limit_policies WHERE sku = ?');
        $stmt->execute([$sku]);
    }

    // ── purchases ─────────────────────────────────────────────────────────────

    /**
     * Record a purchase (always logged, even for un-capped SKUs).
     *
     * @param  string      $sku    Product identifier.
     * @param  int         $userId Buyer user id.
     * @param  int         $qty    Quantity purchased (>= 1).
     * @param  string|null $asOf   Purchase time; defaults to now.
     * @throws \InvalidArgumentException on empty SKU or qty < 1.
     */
    public function record(string $sku, int $userId, int $qty, ?string $asOf = null): void
    {
        $sku = $this->validate($sku, 'SKU');
        if ($qty < 1) {
            throw new \InvalidArgumentException('Quantity must be at least 1.');
        }

        $stmt = $this->db()->prepare(
            'INSERT INTO purchase_limit_records (sku, user_id, qty, purchased_at) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$sku, $userId, $qty, $this->ts($asOf)]);
    }

    /**
     * Quantity a user has purchased of a SKU within the current rolling window.
     *
     * @param string      $sku    Product identifier.
     * @param int         $userId Buyer user id.
     * @param string|null $asOf   Reference time; defaults to now.
     */
    public function purchasedInPeriod(string $sku, int $userId, ?string $asOf = null): int
    {
        $sku    = $this->validate($sku, 'SKU');
        $policy = $this->policy($sku);
        // With no policy there is no window; count is meaningful only relative to
        // a window, so use the policy window if present, else all-time.
        if ($policy !== null) {
            $cutoff = $this->windowCutoff($policy['period_days'], $asOf);
            $stmt   = $this->db()->prepare(
                'SELECT COALESCE(SUM(qty), 0) FROM purchase_limit_records WHERE sku = ? AND user_id = ? AND purchased_at >= ?'
            );
            $stmt->execute([$sku, $userId, $cutoff]);
        } else {
            $stmt = $this->db()->prepare(
                'SELECT COALESCE(SUM(qty), 0) FROM purchase_limit_records WHERE sku = ? AND user_id = ?'
            );
            $stmt->execute([$sku, $userId]);
        }

        return (int)$stmt->fetchColumn();
    }

    /**
     * Remaining units a user may purchase, or null if the SKU is un-capped.
     *
     * @return int|null Never negative.
     */
    public function remaining(string $sku, int $userId, ?string $asOf = null): ?int
    {
        $sku    = $this->validate($sku, 'SKU');
        $policy = $this->policy($sku);
        if ($policy === null) {
            return null;
        }

        return max(0, $policy['max_qty'] - $this->purchasedInPeriod($sku, $userId, $asOf));
    }

    /**
     * Whether a user may purchase $qty more of a SKU now. Un-capped SKUs always allow.
     *
     * @throws \InvalidArgumentException on qty < 1.
     */
    public function canPurchase(string $sku, int $userId, int $qty, ?string $asOf = null): bool
    {
        if ($qty < 1) {
            throw new \InvalidArgumentException('Quantity must be at least 1.');
        }
        $remaining = $this->remaining($sku, $userId, $asOf);

        return $remaining === null || $qty <= $remaining;
    }

    // ── private ───────────────────────────────────────────────────────────────

    /**
     * @return array{max_qty:int,period_days:int}|null
     */
    private function policy(string $sku): ?array
    {
        $stmt = $this->db()->prepare('SELECT max_qty, period_days FROM purchase_limit_policies WHERE sku = ?');
        $stmt->execute([$sku]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : ['max_qty' => (int)$row['max_qty'], 'period_days' => (int)$row['period_days']];
    }

    private function windowCutoff(int $periodDays, ?string $asOf): string
    {
        $epoch = strtotime($asOf ?? 'now');
        if ($epoch === false) {
            throw new \InvalidArgumentException('Invalid reference time.');
        }

        return date('Y-m-d H:i:s', $epoch - $periodDays * 86400);
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
