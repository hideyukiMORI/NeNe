<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\DbUpsert;
use Nene\Xion\PdoConnection;
use PDO;

/**
 * GiftRegistry — wish-list of desired items that others can claim.
 *
 * Models a wedding/baby-style gift registry: the owner lists items with a
 * desired quantity, and gift-givers `claim()` quantities so the same gift
 * isn't bought twice. Distinct from `Wishlist` (FT81), a private saved-item
 * list with no third-party claiming: the novel mechanic here is the
 * claimed-vs-desired accounting that prevents over-gifting.
 *
 * ## Usage
 *
 * ```php
 * $gr = new GiftRegistry($pdo);
 *
 * $gr->addItem('wedding-alice', 'toaster', desiredQty: 1);
 * $gr->addItem('wedding-alice', 'wine-glass', desiredQty: 6);
 *
 * $gr->claim('wedding-alice', 'wine-glass', 4); // true (4/6)
 * $gr->remaining('wedding-alice', 'wine-glass'); // 2
 * $gr->claim('wedding-alice', 'wine-glass', 3);  // false — would exceed
 * $gr->isFulfilled('wedding-alice', 'toaster');  // false (0/1)
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE gift_registry_items (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     registry    VARCHAR(150) NOT NULL,
 *     item        VARCHAR(190) NOT NULL,
 *     desired_qty INTEGER      NOT NULL DEFAULT 1,
 *     claimed_qty INTEGER      NOT NULL DEFAULT 0,
 *     created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (registry, item)
 * );
 * ```
 */
final class GiftRegistry
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Add an item to a registry (or update its desired quantity). Idempotent;
     * the claimed quantity is preserved.
     *
     * @param  string $registry   Registry identifier.
     * @param  string $item       Item identifier.
     * @param  int    $desiredQty Desired quantity (>= 1).
     * @throws \InvalidArgumentException on empty names or desiredQty < 1.
     */
    public function addItem(string $registry, string $item, int $desiredQty = 1): void
    {
        $registry = $this->validate($registry, 'Registry');
        $item     = $this->validate($item, 'Item');
        if ($desiredQty < 1) {
            throw new \InvalidArgumentException('Desired quantity must be at least 1.');
        }

        DbUpsert::run(
            $this->db(),
            table:        'gift_registry_items',
            data:         ['registry' => $registry, 'item' => $item, 'desired_qty' => $desiredQty],
            conflictCols: ['registry', 'item'],
            updateCols:   ['desired_qty'],
        );
    }

    /**
     * Claim a quantity of an item. Fails (returns false) if the item is unknown
     * or the claim would exceed the desired quantity.
     *
     * @param  string $registry Registry identifier.
     * @param  string $item     Item identifier.
     * @param  int    $qty      Quantity to claim (>= 1).
     * @return bool             True if claimed; false if unavailable.
     * @throws \InvalidArgumentException on empty names or qty < 1.
     */
    public function claim(string $registry, string $item, int $qty = 1): bool
    {
        $registry = $this->validate($registry, 'Registry');
        $item     = $this->validate($item, 'Item');
        if ($qty < 1) {
            throw new \InvalidArgumentException('Quantity must be at least 1.');
        }

        $db             = $this->db();
        $ownTransaction = !$db->inTransaction();
        if ($ownTransaction) {
            $db->beginTransaction();
        }
        try {
            $row = $this->row($registry, $item);
            if ($row === null || (int)$row['claimed_qty'] + $qty > (int)$row['desired_qty']) {
                if ($ownTransaction) {
                    $db->commit();
                }

                return false;
            }

            $stmt = $db->prepare('UPDATE gift_registry_items SET claimed_qty = claimed_qty + ? WHERE registry = ? AND item = ?');
            $stmt->execute([$qty, $registry, $item]);

            if ($ownTransaction) {
                $db->commit();
            }

            return true;
        } catch (\Throwable $e) {
            if ($ownTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Release a previously-claimed quantity (claim cancelled). Clamps at 0.
     */
    public function unclaim(string $registry, string $item, int $qty = 1): void
    {
        $registry = $this->validate($registry, 'Registry');
        $item     = $this->validate($item, 'Item');
        if ($qty < 1) {
            throw new \InvalidArgumentException('Quantity must be at least 1.');
        }

        $stmt = $this->db()->prepare(
            'UPDATE gift_registry_items SET claimed_qty = MAX(0, claimed_qty - ?) WHERE registry = ? AND item = ?'
        );
        $stmt->execute([$qty, $registry, $item]);
    }

    /**
     * Quantity claimed for an item (0 if unknown).
     */
    public function claimedQty(string $registry, string $item): int
    {
        $row = $this->row($this->validate($registry, 'Registry'), $this->validate($item, 'Item'));

        return $row === null ? 0 : (int)$row['claimed_qty'];
    }

    /**
     * Remaining unclaimed quantity for an item (0 if unknown).
     */
    public function remaining(string $registry, string $item): int
    {
        $row = $this->row($this->validate($registry, 'Registry'), $this->validate($item, 'Item'));

        return $row === null ? 0 : max(0, (int)$row['desired_qty'] - (int)$row['claimed_qty']);
    }

    /**
     * Whether an item is fully claimed (claimed >= desired). False if unknown.
     */
    public function isFulfilled(string $registry, string $item): bool
    {
        $row = $this->row($this->validate($registry, 'Registry'), $this->validate($item, 'Item'));

        return $row !== null && (int)$row['claimed_qty'] >= (int)$row['desired_qty'];
    }

    /**
     * All items in a registry with their claim accounting, by item name.
     *
     * @return array<int,array{item:string,desired:int,claimed:int,remaining:int}>
     */
    public function items(string $registry): array
    {
        $registry = $this->validate($registry, 'Registry');
        $stmt     = $this->db()->prepare(
            'SELECT item, desired_qty, claimed_qty FROM gift_registry_items WHERE registry = ? ORDER BY item ASC'
        );
        $stmt->execute([$registry]);

        $out = [];
        /** @var array<string,mixed> $row */
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $desired = (int)$row['desired_qty'];
            $claimed = (int)$row['claimed_qty'];
            $out[]   = [
                'item'      => (string)$row['item'],
                'desired'   => $desired,
                'claimed'   => $claimed,
                'remaining' => max(0, $desired - $claimed),
            ];
        }

        return $out;
    }

    /**
     * Remove an item from a registry. No-op if absent.
     */
    public function removeItem(string $registry, string $item): void
    {
        $registry = $this->validate($registry, 'Registry');
        $item     = $this->validate($item, 'Item');
        $stmt     = $this->db()->prepare('DELETE FROM gift_registry_items WHERE registry = ? AND item = ?');
        $stmt->execute([$registry, $item]);
    }

    // ── private ───────────────────────────────────────────────────────────────

    /**
     * @return array<string,mixed>|null
     */
    private function row(string $registry, string $item): ?array
    {
        $stmt = $this->db()->prepare('SELECT desired_qty, claimed_qty FROM gift_registry_items WHERE registry = ? AND item = ?');
        $stmt->execute([$registry, $item]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
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
