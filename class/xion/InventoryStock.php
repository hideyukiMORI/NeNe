<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * InventoryStock — product/SKU stock tracking with atomic reserve/release/commit.
 *
 * Tracks available, reserved, and committed stock quantities.
 * Uses a transaction log for auditing. The three-phase stock model:
 *
 * - **reserve**: decrement available, increment reserved (hold for checkout)
 * - **release**: decrement reserved, increment available (cancel hold)
 * - **commit**: decrement reserved (purchase confirmed, stock consumed)
 *
 * ## Usage
 *
 * ```php
 * $inv = new InventoryStock($pdo);
 *
 * $inv->setStock('SKU-001', 100);
 * $inv->reserve('SKU-001', 5, 'cart-42');   // holds 5 units
 * $inv->commit('SKU-001', 5, 'order-99');   // finalizes sale
 * $inv->available('SKU-001');               // 95
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE inventory_stock (
 *     id        INTEGER PRIMARY KEY AUTOINCREMENT,
 *     sku       VARCHAR(100) NOT NULL UNIQUE,
 *     available INTEGER      NOT NULL DEFAULT 0,
 *     reserved  INTEGER      NOT NULL DEFAULT 0,
 *     updated_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 *
 * CREATE TABLE inventory_log (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     sku        VARCHAR(100) NOT NULL,
 *     action     VARCHAR(20)  NOT NULL,
 *     qty        INTEGER      NOT NULL,
 *     reference  TEXT         NULL,
 *     created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class InventoryStock
{
    public const ACTION_SET     = 'set';
    public const ACTION_RESERVE = 'reserve';
    public const ACTION_RELEASE = 'release';
    public const ACTION_COMMIT  = 'commit';
    public const ACTION_ADJUST  = 'adjust';

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Set the available stock for a SKU (creates record if needed).
     *
     * @throws \InvalidArgumentException on empty SKU or negative qty.
     */
    public function setStock(string $sku, int $qty, ?string $reference = null): void
    {
        $sku = $this->validateSku($sku);
        if ($qty < 0) {
            throw new \InvalidArgumentException('qty must not be negative.');
        }
        $now    = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $driver = $this->db()->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $sql = 'INSERT INTO inventory_stock (sku, available, updated_at)
                    VALUES (:sku, :qty, :now)
                    ON CONFLICT (sku) DO UPDATE SET available = excluded.available, updated_at = excluded.updated_at';
        } else {
            $sql = 'INSERT INTO inventory_stock (sku, available, updated_at)
                    VALUES (:sku, :qty, :now)
                    ON DUPLICATE KEY UPDATE available = VALUES(available), updated_at = VALUES(updated_at)';
        }
        $this->db()->prepare($sql)->execute([':sku' => $sku, ':qty' => $qty, ':now' => $now]);
        $this->log($sku, self::ACTION_SET, $qty, $reference);
    }

    /**
     * Reserve stock (hold for checkout). Returns false if insufficient stock.
     */
    public function reserve(string $sku, int $qty, ?string $reference = null): bool
    {
        $sku = $this->validateSku($sku);
        $this->requirePositive($qty);
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'UPDATE inventory_stock
             SET available = available - :qty, reserved = reserved + :qty, updated_at = :now
             WHERE sku = :sku AND available >= :qty'
        );
        $stmt->execute([':qty' => $qty, ':sku' => $sku, ':now' => $now]);
        if ($stmt->rowCount() === 0) {
            return false;
        }
        $this->log($sku, self::ACTION_RESERVE, $qty, $reference);
        return true;
    }

    /**
     * Release a reservation back to available (cancel hold).
     */
    public function release(string $sku, int $qty, ?string $reference = null): bool
    {
        $sku = $this->validateSku($sku);
        $this->requirePositive($qty);
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'UPDATE inventory_stock
             SET available = available + :qty, reserved = reserved - :qty, updated_at = :now
             WHERE sku = :sku AND reserved >= :qty'
        );
        $stmt->execute([':qty' => $qty, ':sku' => $sku, ':now' => $now]);
        if ($stmt->rowCount() === 0) {
            return false;
        }
        $this->log($sku, self::ACTION_RELEASE, $qty, $reference);
        return true;
    }

    /**
     * Commit reserved stock (purchase confirmed, reservation consumed).
     */
    public function commit(string $sku, int $qty, ?string $reference = null): bool
    {
        $sku = $this->validateSku($sku);
        $this->requirePositive($qty);
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'UPDATE inventory_stock
             SET reserved = reserved - :qty, updated_at = :now
             WHERE sku = :sku AND reserved >= :qty'
        );
        $stmt->execute([':qty' => $qty, ':sku' => $sku, ':now' => $now]);
        if ($stmt->rowCount() === 0) {
            return false;
        }
        $this->log($sku, self::ACTION_COMMIT, $qty, $reference);
        return true;
    }

    /**
     * Adjust available stock by a signed delta (positive = add, negative = remove).
     */
    public function adjust(string $sku, int $delta, ?string $reference = null): bool
    {
        $sku = $this->validateSku($sku);
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'UPDATE inventory_stock
             SET available = available + :delta, updated_at = :now
             WHERE sku = :sku AND available + :delta >= 0'
        );
        $stmt->execute([':delta' => $delta, ':sku' => $sku, ':now' => $now]);
        if ($stmt->rowCount() === 0) {
            return false;
        }
        $this->log($sku, self::ACTION_ADJUST, $delta, $reference);
        return true;
    }

    /**
     * Return the available stock for a SKU (0 if not found).
     */
    public function available(string $sku): int
    {
        $stmt = $this->db()->prepare(
            'SELECT available FROM inventory_stock WHERE sku = :sku'
        );
        $stmt->execute([':sku' => trim($sku)]);
        $val = $stmt->fetchColumn();
        return $val === false ? 0 : (int)$val;
    }

    /**
     * Return the full stock record for a SKU.
     *
     * @return array<string,mixed>|null
     */
    public function stockFor(string $sku): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT sku, available, reserved, updated_at FROM inventory_stock WHERE sku = :sku'
        );
        $stmt->execute([':sku' => trim($sku)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Return stock transaction log for a SKU, newest first.
     *
     * @return list<array<string,mixed>>
     */
    public function logFor(string $sku): array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, sku, action, qty, reference, created_at
             FROM inventory_log WHERE sku = :sku ORDER BY id DESC'
        );
        $stmt->execute([':sku' => trim($sku)]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // ── internal helpers ──────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    private function validateSku(string $sku): string
    {
        $sku = trim($sku);
        if ($sku === '') {
            throw new \InvalidArgumentException('sku must not be empty.');
        }
        return $sku;
    }

    private function requirePositive(int $qty): void
    {
        if ($qty <= 0) {
            throw new \InvalidArgumentException('qty must be greater than zero.');
        }
    }

    private function log(string $sku, string $action, int $qty, ?string $reference): void
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'INSERT INTO inventory_log (sku, action, qty, reference, created_at)
             VALUES (:sku, :action, :qty, :ref, :now)'
        );
        $stmt->execute([':sku' => $sku, ':action' => $action, ':qty' => $qty, ':ref' => $reference, ':now' => $now]);
    }
}
