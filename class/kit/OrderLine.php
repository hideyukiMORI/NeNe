<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * OrderLine — e-commerce order header with line items.
 *
 * Two-table design: `orders` for order headers and `order_lines` for
 * individual line items. Prices are stored as integer cents to avoid
 * floating-point rounding issues.
 *
 * Status lifecycle: pending → confirmed → shipped → delivered | cancelled.
 *
 * ## Usage
 *
 * ```php
 * $ol = new OrderLine($pdo);
 *
 * $orderId = $ol->createOrder('user-1', 'USD', 'addr-42');
 * $ol->addLine($orderId, 'SKU-001', 'Widget', 2, 1999);  // 2 × $19.99
 * $ol->addLine($orderId, 'SKU-002', 'Gadget', 1, 4999);  // 1 × $49.99
 *
 * $ol->confirm($orderId);
 * $total  = $ol->totalCents($orderId);  // 6997 = 3998 + 2999
 * $lines  = $ol->lines($orderId);
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE orders (
 *     id            INTEGER PRIMARY KEY AUTOINCREMENT,
 *     user_id       VARCHAR(255) NOT NULL,
 *     status        VARCHAR(20)  NOT NULL DEFAULT 'pending',
 *     currency      VARCHAR(3)   NOT NULL DEFAULT 'USD',
 *     address_ref   TEXT         NULL,
 *     confirmed_at  DATETIME     NULL,
 *     shipped_at    DATETIME     NULL,
 *     delivered_at  DATETIME     NULL,
 *     cancelled_at  DATETIME     NULL,
 *     created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 *
 * CREATE TABLE order_lines (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     order_id   INTEGER      NOT NULL,
 *     sku        VARCHAR(100) NOT NULL,
 *     name       TEXT         NOT NULL,
 *     qty        INTEGER      NOT NULL,
 *     unit_cents INTEGER      NOT NULL,
 *     created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class OrderLine
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_SHIPPED   = 'shipped';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_CANCELLED = 'cancelled';

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Create a new order header.
     *
     * @return int Order ID.
     * @throws \InvalidArgumentException on empty user_id.
     */
    public function createOrder(string $userId, string $currency = 'USD', ?string $addressRef = null): int
    {
        $userId = trim($userId);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'INSERT INTO orders (user_id, currency, address_ref, created_at)
             VALUES (:uid, :currency, :addr, :now)'
        );
        $stmt->execute([
            ':uid'      => $userId,
            ':currency' => strtoupper(trim($currency)),
            ':addr'     => $addressRef,
            ':now'      => $now,
        ]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Find an order by ID.
     *
     * @return array<string,mixed>|null
     */
    public function findOrder(int $id): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, user_id, status, currency, address_ref,
                    confirmed_at, shipped_at, delivered_at, cancelled_at, created_at
             FROM orders WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Add a line item to an order.
     *
     * @return int Line item ID.
     * @throws \InvalidArgumentException on empty SKU/name, qty ≤ 0, or negative price.
     */
    public function addLine(int $orderId, string $sku, string $name, int $qty, int $unitCents): int
    {
        $sku  = trim($sku);
        $name = trim($name);
        if ($sku === '') {
            throw new \InvalidArgumentException('sku must not be empty.');
        }
        if ($name === '') {
            throw new \InvalidArgumentException('name must not be empty.');
        }
        if ($qty <= 0) {
            throw new \InvalidArgumentException('qty must be greater than zero.');
        }
        if ($unitCents < 0) {
            throw new \InvalidArgumentException('unit_cents must not be negative.');
        }
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'INSERT INTO order_lines (order_id, sku, name, qty, unit_cents, created_at)
             VALUES (:oid, :sku, :name, :qty, :cents, :now)'
        );
        $stmt->execute([
            ':oid'   => $orderId,
            ':sku'   => $sku,
            ':name'  => $name,
            ':qty'   => $qty,
            ':cents' => $unitCents,
            ':now'   => $now,
        ]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Return all line items for an order, in insertion order.
     *
     * @return list<array<string,mixed>>
     */
    public function lines(int $orderId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, order_id, sku, name, qty, unit_cents, created_at
             FROM order_lines WHERE order_id = :oid ORDER BY id ASC'
        );
        $stmt->execute([':oid' => $orderId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Return the total order amount in cents (sum of qty × unit_cents).
     */
    public function totalCents(int $orderId): int
    {
        $stmt = $this->db()->prepare(
            'SELECT COALESCE(SUM(qty * unit_cents), 0) FROM order_lines WHERE order_id = :oid'
        );
        $stmt->execute([':oid' => $orderId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Confirm an order (pending → confirmed).
     *
     * @return bool True if found and updated.
     */
    public function confirm(int $id): bool
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            "UPDATE orders SET status = 'confirmed', confirmed_at = :now
             WHERE id = :id AND status = 'pending'"
        );
        $stmt->execute([':now' => $now, ':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Ship an order (confirmed → shipped).
     *
     * @return bool True if found and updated.
     */
    public function ship(int $id): bool
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            "UPDATE orders SET status = 'shipped', shipped_at = :now
             WHERE id = :id AND status = 'confirmed'"
        );
        $stmt->execute([':now' => $now, ':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Mark an order as delivered (shipped → delivered).
     *
     * @return bool True if found and updated.
     */
    public function deliver(int $id): bool
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            "UPDATE orders SET status = 'delivered', delivered_at = :now
             WHERE id = :id AND status = 'shipped'"
        );
        $stmt->execute([':now' => $now, ':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Cancel an order (pending or confirmed only).
     *
     * @return bool True if found and updated.
     */
    public function cancel(int $id): bool
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            "UPDATE orders SET status = 'cancelled', cancelled_at = :now
             WHERE id = :id AND status IN ('pending', 'confirmed')"
        );
        $stmt->execute([':now' => $now, ':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Return orders for a user, newest first.
     *
     * @return list<array<string,mixed>>
     */
    public function forUser(string $userId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, user_id, status, currency, address_ref,
                    confirmed_at, shipped_at, delivered_at, cancelled_at, created_at
             FROM orders WHERE user_id = :uid ORDER BY id DESC'
        );
        $stmt->execute([':uid' => trim($userId)]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // ── internal helpers ──────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
