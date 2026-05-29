<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\DbUpsert;
use Nene\Xion\PdoConnection;
use PDO;

/**
 * WeightedPicker — weighted random selection from a named pool.
 *
 * Stores items with integer weights per pool and picks one with probability
 * proportional to its weight. Useful for weighted A/B variant assignment,
 * ad/creative rotation, prize draws, or load distribution favouring more
 * capable workers. Items with weight 0 are retained but never selected.
 *
 * `pick()` uses a random roll; `pick($pool, $roll)` accepts an explicit roll
 * in `[0, totalWeight)` for deterministic/reproducible selection (and tests).
 *
 * ## Usage
 *
 * ```php
 * $wp = new WeightedPicker($pdo);
 *
 * $wp->setWeight('variant', 'A', 70);
 * $wp->setWeight('variant', 'B', 30);
 *
 * $wp->pick('variant');        // 'A' ~70% of the time, 'B' ~30%
 * $wp->pick('variant', 0);     // 'A' (deterministic: roll 0)
 * $wp->pick('variant', 70);    // 'B' (roll 70 lands in B's band)
 *
 * $wp->totalWeight('variant'); // 100
 * $wp->weights('variant');     // [['item'=>'A','weight'=>70], ...]
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE weighted_entries (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     pool       VARCHAR(100) NOT NULL,
 *     item       VARCHAR(190) NOT NULL,
 *     weight     INTEGER      NOT NULL DEFAULT 0,
 *     created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (pool, item)
 * );
 * ```
 */
final class WeightedPicker
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Set (or update) an item's weight in a pool. Idempotent per (pool, item).
     *
     * @param  string $pool   Pool name.
     * @param  string $item   Item identifier.
     * @param  int    $weight Selection weight (>= 0; 0 = never picked).
     * @throws \InvalidArgumentException on empty pool/item or negative weight.
     */
    public function setWeight(string $pool, string $item, int $weight): void
    {
        $pool = $this->validate($pool, 'Pool');
        $item = $this->validate($item, 'Item');
        if ($weight < 0) {
            throw new \InvalidArgumentException('Weight must not be negative.');
        }

        DbUpsert::run(
            $this->db(),
            table:        'weighted_entries',
            data:         ['pool' => $pool, 'item' => $item, 'weight' => $weight],
            conflictCols: ['pool', 'item'],
            updateCols:   ['weight'],
        );
    }

    /**
     * Pick an item from a pool with probability proportional to weight.
     *
     * @param  string   $pool Pool name.
     * @param  int|null  $roll Explicit roll in [0, totalWeight) for deterministic
     *                         selection; null picks a random roll.
     * @return string|null     Selected item, or null if the pool has no positive weight.
     * @throws \InvalidArgumentException on empty pool or out-of-range roll.
     */
    public function pick(string $pool, ?int $roll = null): ?string
    {
        $pool    = $this->validate($pool, 'Pool');
        $entries = $this->positiveEntries($pool);
        $total   = 0;
        foreach ($entries as $e) {
            $total += $e['weight'];
        }
        if ($total === 0) {
            return null;
        }

        if ($roll === null) {
            $roll = random_int(0, $total - 1);
        } elseif ($roll < 0 || $roll >= $total) {
            throw new \InvalidArgumentException("Roll must be in [0, {$total}).");
        }

        $acc = 0;
        foreach ($entries as $e) {
            $acc += $e['weight'];
            if ($roll < $acc) {
                return $e['item'];
            }
        }

        return $entries[count($entries) - 1]['item']; // unreachable; defensive
    }

    /**
     * Total weight of a pool.
     */
    public function totalWeight(string $pool): int
    {
        $pool = $this->validate($pool, 'Pool');
        $stmt = $this->db()->prepare('SELECT COALESCE(SUM(weight), 0) FROM weighted_entries WHERE pool = ?');
        $stmt->execute([$pool]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * List a pool's items and weights, item order.
     *
     * @return array<int,array{item:string,weight:int}>
     */
    public function weights(string $pool): array
    {
        $pool = $this->validate($pool, 'Pool');
        $stmt = $this->db()->prepare('SELECT item, weight FROM weighted_entries WHERE pool = ? ORDER BY item ASC');
        $stmt->execute([$pool]);

        $out = [];
        /** @var array<string,mixed> $row */
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = ['item' => (string)$row['item'], 'weight' => (int)$row['weight']];
        }

        return $out;
    }

    /**
     * Remove an item from a pool. No-op if absent.
     */
    public function remove(string $pool, string $item): void
    {
        $pool = $this->validate($pool, 'Pool');
        $item = $this->validate($item, 'Item');
        $stmt = $this->db()->prepare('DELETE FROM weighted_entries WHERE pool = ? AND item = ?');
        $stmt->execute([$pool, $item]);
    }

    /**
     * Remove all items from a pool. No-op if empty.
     */
    public function clear(string $pool): void
    {
        $pool = $this->validate($pool, 'Pool');
        $stmt = $this->db()->prepare('DELETE FROM weighted_entries WHERE pool = ?');
        $stmt->execute([$pool]);
    }

    // ── private ───────────────────────────────────────────────────────────────

    /**
     * Pool entries with positive weight, in stable id order.
     *
     * @return array<int,array{item:string,weight:int}>
     */
    private function positiveEntries(string $pool): array
    {
        $stmt = $this->db()->prepare(
            'SELECT item, weight FROM weighted_entries WHERE pool = ? AND weight > 0 ORDER BY id ASC'
        );
        $stmt->execute([$pool]);

        $out = [];
        /** @var array<string,mixed> $row */
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = ['item' => (string)$row['item'], 'weight' => (int)$row['weight']];
        }

        return $out;
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
