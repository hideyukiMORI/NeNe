<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * ProductBundle — product bundle definitions with included items.
 *
 * A bundle groups multiple SKUs with quantities and has its own bundle-level
 * price (often less than the sum of individual prices). Bundles have an
 * active/inactive state and optional availability window.
 *
 * ## Usage
 *
 * ```php
 * $pb = new ProductBundle($pdo);
 *
 * // Create a bundle
 * $id = $pb->create('starter-kit', 'Starter Kit', 2999);
 * $pb->addItem($id, 'SKU-BOOK', 1);
 * $pb->addItem($id, 'SKU-PEN',  2);
 *
 * // Activate
 * $pb->activate($id);
 *
 * // Query
 * $bundle = $pb->find($id);
 * $items  = $pb->items($id);
 * $active = $pb->allActive();
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE product_bundles (
 *     id           INTEGER PRIMARY KEY AUTOINCREMENT,
 *     bundle_key   VARCHAR(100) NOT NULL UNIQUE,
 *     name         VARCHAR(255) NOT NULL,
 *     price_cents  INTEGER      NOT NULL,
 *     is_active    TINYINT(1)   NOT NULL DEFAULT 0,
 *     created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * CREATE TABLE product_bundle_items (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     bundle_id  INTEGER      NOT NULL,
 *     sku        VARCHAR(255) NOT NULL,
 *     quantity   INTEGER      NOT NULL DEFAULT 1,
 *     UNIQUE (bundle_id, sku)
 * );
 * ```
 */
final class ProductBundle
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Create a new bundle (inactive by default).
     *
     * @return int Row ID.
     * @throws \InvalidArgumentException on invalid arguments.
     * @throws \RuntimeException if bundle_key already exists.
     */
    public function create(string $bundleKey, string $name, int $priceCents): int
    {
        $bundleKey = trim($bundleKey);
        $name      = trim($name);
        if ($bundleKey === '') {
            throw new \InvalidArgumentException('bundleKey must not be empty.');
        }
        if ($name === '') {
            throw new \InvalidArgumentException('name must not be empty.');
        }
        if ($priceCents < 0) {
            throw new \InvalidArgumentException('priceCents must be >= 0.');
        }

        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        try {
            $stmt = $this->db()->prepare(
                'INSERT INTO product_bundles (bundle_key, name, price_cents, is_active, created_at, updated_at)
                 VALUES (:key, :name, :cents, 0, :now, :now)'
            );
            $stmt->execute([':key' => $bundleKey, ':name' => $name, ':cents' => $priceCents, ':now' => $now]);
        } catch (\PDOException $e) {
            throw new \RuntimeException("Bundle key '{$bundleKey}' already exists.", 0, $e);
        }
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Find a bundle by ID.
     *
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM product_bundles WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Find a bundle by its key.
     *
     * @return array<string,mixed>|null
     */
    public function findByKey(string $bundleKey): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM product_bundles WHERE bundle_key = :key');
        $stmt->execute([':key' => trim($bundleKey)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Update the bundle name and/or price.
     *
     * @return bool True if found and updated.
     */
    public function update(int $id, string $name, int $priceCents): bool
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('name must not be empty.');
        }
        if ($priceCents < 0) {
            throw new \InvalidArgumentException('priceCents must be >= 0.');
        }
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'UPDATE product_bundles SET name = :name, price_cents = :cents, updated_at = :now WHERE id = :id'
        );
        $stmt->execute([':name' => $name, ':cents' => $priceCents, ':now' => $now, ':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Activate a bundle (make it publicly available).
     *
     * @return bool True if found and updated.
     */
    public function activate(int $id): bool
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'UPDATE product_bundles SET is_active = 1, updated_at = :now WHERE id = :id'
        );
        $stmt->execute([':now' => $now, ':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Deactivate a bundle.
     *
     * @return bool True if found and updated.
     */
    public function deactivate(int $id): bool
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'UPDATE product_bundles SET is_active = 0, updated_at = :now WHERE id = :id'
        );
        $stmt->execute([':now' => $now, ':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Delete a bundle and its items.
     *
     * @return bool True if found and deleted.
     */
    public function delete(int $id): bool
    {
        $stmt = $this->db()->prepare('DELETE FROM product_bundle_items WHERE bundle_id = :id');
        $stmt->execute([':id' => $id]);

        $stmt2 = $this->db()->prepare('DELETE FROM product_bundles WHERE id = :id');
        $stmt2->execute([':id' => $id]);
        return $stmt2->rowCount() > 0;
    }

    /**
     * Add a SKU with quantity to a bundle (upserts quantity if already present).
     *
     * @return bool True if inserted; false if quantity was updated.
     */
    public function addItem(int $bundleId, string $sku, int $quantity = 1): bool
    {
        $sku = trim($sku);
        if ($sku === '') {
            throw new \InvalidArgumentException('sku must not be empty.');
        }
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('quantity must be > 0.');
        }

        // SQLite upsert
        $stmt = $this->db()->prepare(
            'INSERT INTO product_bundle_items (bundle_id, sku, quantity)
             VALUES (:bid, :sku, :qty)
             ON CONFLICT (bundle_id, sku) DO UPDATE SET quantity = :qty2'
        );
        try {
            $stmt->execute([':bid' => $bundleId, ':sku' => $sku, ':qty' => $quantity, ':qty2' => $quantity]);
            return (int)$this->db()->lastInsertId() > 0;
        } catch (\PDOException) {
            // MySQL fallback
            $stmt2 = $this->db()->prepare(
                'INSERT INTO product_bundle_items (bundle_id, sku, quantity)
                 VALUES (:bid, :sku, :qty)
                 ON DUPLICATE KEY UPDATE quantity = :qty2'
            );
            $stmt2->execute([':bid' => $bundleId, ':sku' => $sku, ':qty' => $quantity, ':qty2' => $quantity]);
            return true;
        }
    }

    /**
     * Remove a SKU from a bundle.
     *
     * @return bool True if found and removed.
     */
    public function removeItem(int $bundleId, string $sku): bool
    {
        $stmt = $this->db()->prepare(
            'DELETE FROM product_bundle_items WHERE bundle_id = :bid AND sku = :sku'
        );
        $stmt->execute([':bid' => $bundleId, ':sku' => trim($sku)]);
        return $stmt->rowCount() > 0;
    }

    /**
     * List items in a bundle.
     *
     * @return list<array<string,mixed>>
     */
    public function items(int $bundleId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM product_bundle_items WHERE bundle_id = :bid ORDER BY sku ASC'
        );
        $stmt->execute([':bid' => $bundleId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * List all active bundles.
     *
     * @return list<array<string,mixed>>
     */
    public function allActive(): array
    {
        $stmt = $this->db()->query(
            'SELECT * FROM product_bundles WHERE is_active = 1 ORDER BY name ASC'
        );
        return $stmt !== false ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
