<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * ShoppingCart — cart item management with quantity and price tracking.
 *
 * A cart is identified by a string `cart_id` (typically the session ID or
 * a user ID). Items are keyed by SKU; adding the same SKU increments qty.
 * Prices are stored as integer cents to avoid floating-point rounding.
 *
 * ## Usage
 *
 * ```php
 * $cart = new ShoppingCart($pdo);
 *
 * $cart->add('cart-abc', 'SKU-001', 2, 1500);  // 2 × ¥1500
 * $cart->add('cart-abc', 'SKU-001', 1, 1500);  // qty becomes 3
 * $cart->add('cart-abc', 'SKU-002', 1, 800);
 *
 * $cart->total('cart-abc');     // 3*1500 + 1*800 = 5300
 * $cart->itemCount('cart-abc'); // 2 distinct SKUs
 * $cart->qty('cart-abc');       // 4 total units
 *
 * $cart->setQty('cart-abc', 'SKU-001', 1);
 * $cart->remove('cart-abc', 'SKU-002');
 * $cart->clear('cart-abc');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE cart_items (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     cart_id    VARCHAR(255) NOT NULL,
 *     sku        VARCHAR(255) NOT NULL,
 *     qty        INTEGER      NOT NULL DEFAULT 1,
 *     unit_price INTEGER      NOT NULL DEFAULT 0,
 *     created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (cart_id, sku)
 * );
 * ```
 */
final class ShoppingCart
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Add an item (or increase quantity if the SKU already exists in the cart).
     *
     * @param int $qty       Must be ≥ 1.
     * @param int $unitPrice Price in smallest currency unit (e.g. cents); must be ≥ 0.
     * @throws \InvalidArgumentException on validation failure.
     */
    public function add(string $cartId, string $sku, int $qty, int $unitPrice): void
    {
        [$cartId, $sku] = $this->validateCartSku($cartId, $sku);
        if ($qty < 1) {
            throw new \InvalidArgumentException('qty must be at least 1.');
        }
        if ($unitPrice < 0) {
            throw new \InvalidArgumentException('unit_price must be non-negative.');
        }

        $db = $this->db();
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $db->prepare(
                'INSERT INTO cart_items (cart_id, sku, qty, unit_price)
                 VALUES (:cid, :sku, :qty, :price)
                 ON CONFLICT (cart_id, sku)
                 DO UPDATE SET qty        = qty + excluded.qty,
                               unit_price = excluded.unit_price,
                               updated_at = CURRENT_TIMESTAMP'
            )->execute([':cid' => $cartId, ':sku' => $sku, ':qty' => $qty, ':price' => $unitPrice]);
        } else {
            $db->prepare(
                'INSERT INTO cart_items (cart_id, sku, qty, unit_price)
                 VALUES (:cid, :sku, :qty, :price)
                 ON DUPLICATE KEY UPDATE qty        = qty + VALUES(qty),
                                         unit_price = VALUES(unit_price),
                                         updated_at = CURRENT_TIMESTAMP'
            )->execute([':cid' => $cartId, ':sku' => $sku, ':qty' => $qty, ':price' => $unitPrice]);
        }
    }

    /**
     * Set the exact quantity for a SKU. Removes the item if qty reaches 0.
     *
     * @return bool True if the item existed.
     * @throws \InvalidArgumentException if qty is negative.
     */
    public function setQty(string $cartId, string $sku, int $qty): bool
    {
        [$cartId, $sku] = $this->validateCartSku($cartId, $sku);
        if ($qty < 0) {
            throw new \InvalidArgumentException('qty must not be negative.');
        }
        if ($qty === 0) {
            return $this->remove($cartId, $sku);
        }
        $stmt = $this->db()->prepare(
            'UPDATE cart_items SET qty = :qty, updated_at = CURRENT_TIMESTAMP
             WHERE cart_id = :cid AND sku = :sku'
        );
        $stmt->execute([':qty' => $qty, ':cid' => $cartId, ':sku' => $sku]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Remove a SKU from the cart entirely.
     *
     * @return bool True if the item existed.
     */
    public function remove(string $cartId, string $sku): bool
    {
        [$cartId, $sku] = $this->validateCartSku($cartId, $sku);
        $stmt = $this->db()->prepare(
            'DELETE FROM cart_items WHERE cart_id = :cid AND sku = :sku'
        );
        $stmt->execute([':cid' => $cartId, ':sku' => $sku]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Clear all items from a cart.
     *
     * @return int Number of items removed.
     */
    public function clear(string $cartId): int
    {
        $cartId = trim($cartId);
        $stmt   = $this->db()->prepare('DELETE FROM cart_items WHERE cart_id = :cid');
        $stmt->execute([':cid' => $cartId]);
        return $stmt->rowCount();
    }

    /**
     * Check whether a SKU is in the cart.
     */
    public function has(string $cartId, string $sku): bool
    {
        [$cartId, $sku] = $this->validateCartSku($cartId, $sku);
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM cart_items WHERE cart_id = :cid AND sku = :sku'
        );
        $stmt->execute([':cid' => $cartId, ':sku' => $sku]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Get all items in a cart.
     *
     * @return list<array{id: int, cart_id: string, sku: string, qty: int, unit_price: int, updated_at: string}>
     */
    public function items(string $cartId): array
    {
        $cartId = trim($cartId);
        $stmt   = $this->db()->prepare(
            'SELECT id, cart_id, sku, qty, unit_price, updated_at
             FROM cart_items WHERE cart_id = :cid ORDER BY id ASC'
        );
        $stmt->execute([':cid' => $cartId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Calculate the cart total in smallest currency unit (sum of qty × unit_price).
     */
    public function total(string $cartId): int
    {
        $cartId = trim($cartId);
        $stmt   = $this->db()->prepare(
            'SELECT COALESCE(SUM(qty * unit_price), 0) FROM cart_items WHERE cart_id = :cid'
        );
        $stmt->execute([':cid' => $cartId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Count the total number of units across all SKUs (sum of qty).
     */
    public function qty(string $cartId): int
    {
        $cartId = trim($cartId);
        $stmt   = $this->db()->prepare(
            'SELECT COALESCE(SUM(qty), 0) FROM cart_items WHERE cart_id = :cid'
        );
        $stmt->execute([':cid' => $cartId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Count the number of distinct SKUs in the cart.
     */
    public function itemCount(string $cartId): int
    {
        $cartId = trim($cartId);
        $stmt   = $this->db()->prepare(
            'SELECT COUNT(*) FROM cart_items WHERE cart_id = :cid'
        );
        $stmt->execute([':cid' => $cartId]);
        return (int)$stmt->fetchColumn();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    /**
     * @return array{string, string}
     */
    private function validateCartSku(string $cartId, string $sku): array
    {
        $cartId = trim($cartId);
        $sku    = trim($sku);
        if ($cartId === '') {
            throw new \InvalidArgumentException('cart_id must not be empty.');
        }
        if ($sku === '') {
            throw new \InvalidArgumentException('sku must not be empty.');
        }
        return [$cartId, $sku];
    }
}
