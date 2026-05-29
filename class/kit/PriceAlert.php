<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\DbUpsert;
use Nene\Xion\PdoConnection;
use PDO;

/**
 * PriceAlert — notify users when an item's price drops to their target.
 *
 * Users watch an item with a target price (integer cents); a price-feed job
 * calls {@see PriceAlert::check()} with the current price, which fires (and
 * marks triggered) every watcher whose target is at or above it. Distinct from
 * `StockAlert` (FT232, back-in-stock) and `AlertRule` (metric thresholds):
 * this is consumer price-drop watching.
 *
 * One alert per `(user_id, item)`; re-watching updates the target and re-arms.
 *
 * ## Usage
 *
 * ```php
 * $pa = new PriceAlert($pdo);
 *
 * $pa->watch(userId: 1, item: 'sku-9', targetCents: 5000); // alert at/below $50
 * $pa->watch(2, 'sku-9', 4500);
 *
 * // Price feed reports $48.00:
 * $fired = $pa->check('sku-9', 4800); // [1] — user 1 (target 5000 >= 4800); user 2 (4500) not yet
 * // each fired alert is marked triggered and won't re-fire until re-watched
 *
 * $pa->pending('sku-9');   // still-watching user ids
 * $pa->unwatch(1, 'sku-9');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE price_alerts (
 *     id           INTEGER PRIMARY KEY AUTOINCREMENT,
 *     user_id      BIGINT       NOT NULL,
 *     item         VARCHAR(190) NOT NULL,
 *     target_cents INTEGER      NOT NULL,
 *     triggered    INTEGER      NOT NULL DEFAULT 0,
 *     created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     triggered_at DATETIME     NULL,
 *     UNIQUE (user_id, item)
 * );
 * ```
 */
final class PriceAlert
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Watch an item for a target price (re-arms if already watched).
     *
     * @param  int    $userId      Watcher user id.
     * @param  string $item        Item identifier.
     * @param  int    $targetCents Trigger at/below this price, in cents (> 0).
     * @throws \InvalidArgumentException on empty item or non-positive target.
     */
    public function watch(int $userId, string $item, int $targetCents): void
    {
        $item = $this->validate($item);
        if ($targetCents <= 0) {
            throw new \InvalidArgumentException('Target must be positive.');
        }

        DbUpsert::run(
            $this->db(),
            table:        'price_alerts',
            data:         ['user_id' => $userId, 'item' => $item, 'target_cents' => $targetCents, 'triggered' => 0, 'triggered_at' => null],
            conflictCols: ['user_id', 'item'],
            updateCols:   ['target_cents', 'triggered', 'triggered_at'],
        );
    }

    /**
     * Fire all armed alerts for an item whose target is at/above the current
     * price; mark them triggered. Returns the user ids that fired.
     *
     * @param  string      $item         Item identifier.
     * @param  int         $currentCents Current price in cents.
     * @param  string|null $asOf         Trigger time; defaults to now.
     * @return array<int,int> Watcher user ids that fired (ascending).
     */
    public function check(string $item, int $currentCents, ?string $asOf = null): array
    {
        $item = $this->validate($item);

        $sel = $this->db()->prepare(
            'SELECT user_id FROM price_alerts WHERE item = ? AND triggered = 0 AND target_cents >= ? ORDER BY user_id ASC'
        );
        $sel->execute([$item, $currentCents]);
        $fired = array_map(static fn ($u): int => (int)$u, $sel->fetchAll(PDO::FETCH_COLUMN));
        if ($fired === []) {
            return [];
        }

        $upd = $this->db()->prepare(
            'UPDATE price_alerts SET triggered = 1, triggered_at = ? WHERE item = ? AND triggered = 0 AND target_cents >= ?'
        );
        $upd->execute([$this->ts($asOf), $item, $currentCents]);

        return $fired;
    }

    /**
     * Whether a user is actively watching an item (armed, not yet triggered).
     */
    public function isWatching(int $userId, string $item): bool
    {
        $item = $this->validate($item);
        $stmt = $this->db()->prepare(
            'SELECT 1 FROM price_alerts WHERE user_id = ? AND item = ? AND triggered = 0 LIMIT 1'
        );
        $stmt->execute([$userId, $item]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * A user's target price for an item, or null if not watching.
     */
    public function targetFor(int $userId, string $item): ?int
    {
        $item = $this->validate($item);
        $stmt = $this->db()->prepare('SELECT target_cents FROM price_alerts WHERE user_id = ? AND item = ?');
        $stmt->execute([$userId, $item]);
        $t = $stmt->fetchColumn();

        return $t === false ? null : (int)$t;
    }

    /**
     * User ids still armed (not triggered) for an item, ascending.
     *
     * @return array<int,int>
     */
    public function pending(string $item): array
    {
        $item = $this->validate($item);
        $stmt = $this->db()->prepare(
            'SELECT user_id FROM price_alerts WHERE item = ? AND triggered = 0 ORDER BY user_id ASC'
        );
        $stmt->execute([$item]);

        return array_map(static fn ($u): int => (int)$u, $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Stop watching. No-op if absent.
     */
    public function unwatch(int $userId, string $item): void
    {
        $item = $this->validate($item);
        $stmt = $this->db()->prepare('DELETE FROM price_alerts WHERE user_id = ? AND item = ?');
        $stmt->execute([$userId, $item]);
    }

    // ── private ───────────────────────────────────────────────────────────────

    private function validate(string $item): string
    {
        $item = trim($item);
        if ($item === '') {
            throw new \InvalidArgumentException('Item must not be empty.');
        }

        return $item;
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
