<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * StockTransfer — multi-location stock ledger with location-to-location moves.
 *
 * Tracks SKU quantities across named locations (warehouses, stores) as an
 * append-only delta ledger: `receive()` brings stock into a location, and
 * `transfer()` atomically moves it between locations (guarded against
 * overdraw). Distinct from `InventoryStock` (FT201), which is a single-pool
 * reserve/release/commit model — this is about *where* stock physically sits
 * and moving it around.
 *
 * ## Usage
 *
 * ```php
 * $st = new StockTransfer($pdo);
 *
 * $st->receive('sku-1', 'warehouse', 100);
 * $st->transfer('sku-1', 'warehouse', 'store-a', 30);
 *
 * $st->balance('sku-1', 'warehouse'); // 70
 * $st->balance('sku-1', 'store-a');   // 30
 * $st->totalStock('sku-1');           // 100
 * $st->locations('sku-1');            // [['location'=>'store-a','balance'=>30], ...]
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE stock_ledger (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     sku        VARCHAR(150) NOT NULL,
 *     location   VARCHAR(150) NOT NULL,
 *     delta      INTEGER      NOT NULL,
 *     note       VARCHAR(190) NOT NULL DEFAULT '',
 *     created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class StockTransfer
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Bring stock into a location (inbound, no source).
     *
     * @param  string $sku      Product identifier.
     * @param  string $location Destination location.
     * @param  int    $qty      Quantity received (> 0).
     * @throws \InvalidArgumentException on empty names or qty < 1.
     */
    public function receive(string $sku, string $location, int $qty): void
    {
        $sku      = $this->validate($sku, 'SKU');
        $location = $this->validate($location, 'Location');
        $this->requirePositive($qty);

        $this->append($sku, $location, $qty, 'receive');
    }

    /**
     * Move stock between two locations atomically. Guards against overdrawing
     * the source.
     *
     * @param  string $sku  Product identifier.
     * @param  string $from Source location.
     * @param  string $to   Destination location.
     * @param  int    $qty  Quantity to move (> 0).
     * @throws \InvalidArgumentException on bad input, same from/to, or insufficient stock.
     */
    public function transfer(string $sku, string $from, string $to, int $qty): void
    {
        $sku  = $this->validate($sku, 'SKU');
        $from = $this->validate($from, 'From location');
        $to   = $this->validate($to, 'To location');
        $this->requirePositive($qty);
        if ($from === $to) {
            throw new \InvalidArgumentException('Source and destination must differ.');
        }

        $db             = $this->db();
        $ownTransaction = !$db->inTransaction();
        if ($ownTransaction) {
            $db->beginTransaction();
        }
        try {
            if ($this->balance($sku, $from) < $qty) {
                throw new \InvalidArgumentException("Insufficient stock at {$from} for {$sku}.");
            }
            $this->append($sku, $from, -$qty, "transfer-out:{$to}");
            $this->append($sku, $to, $qty, "transfer-in:{$from}");
            if ($ownTransaction) {
                $db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Current balance of a SKU at a location.
     */
    public function balance(string $sku, string $location): int
    {
        $sku      = $this->validate($sku, 'SKU');
        $location = $this->validate($location, 'Location');
        $stmt     = $this->db()->prepare('SELECT COALESCE(SUM(delta), 0) FROM stock_ledger WHERE sku = ? AND location = ?');
        $stmt->execute([$sku, $location]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Total stock of a SKU across all locations.
     */
    public function totalStock(string $sku): int
    {
        $sku  = $this->validate($sku, 'SKU');
        $stmt = $this->db()->prepare('SELECT COALESCE(SUM(delta), 0) FROM stock_ledger WHERE sku = ?');
        $stmt->execute([$sku]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Locations holding a non-zero balance of a SKU, by location name.
     *
     * @return array<int,array{location:string,balance:int}>
     */
    public function locations(string $sku): array
    {
        $sku  = $this->validate($sku, 'SKU');
        $stmt = $this->db()->prepare(
            'SELECT location, SUM(delta) AS bal FROM stock_ledger WHERE sku = ?
             GROUP BY location HAVING SUM(delta) <> 0 ORDER BY location ASC'
        );
        $stmt->execute([$sku]);

        $out = [];
        /** @var array<string,mixed> $row */
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = ['location' => (string)$row['location'], 'balance' => (int)$row['bal']];
        }

        return $out;
    }

    /**
     * Movement history for a SKU (optionally a single location), newest first.
     *
     * @return array<int,array{location:string,delta:int,note:string,created_at:string}>
     */
    public function history(string $sku, ?string $location = null): array
    {
        $sku = $this->validate($sku, 'SKU');

        if ($location === null) {
            $stmt = $this->db()->prepare('SELECT location, delta, note, created_at FROM stock_ledger WHERE sku = ? ORDER BY id DESC');
            $stmt->execute([$sku]);
        } else {
            $location = $this->validate($location, 'Location');
            $stmt     = $this->db()->prepare('SELECT location, delta, note, created_at FROM stock_ledger WHERE sku = ? AND location = ? ORDER BY id DESC');
            $stmt->execute([$sku, $location]);
        }

        $out = [];
        /** @var array<string,mixed> $row */
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = [
                'location'   => (string)$row['location'],
                'delta'      => (int)$row['delta'],
                'note'       => (string)$row['note'],
                'created_at' => (string)$row['created_at'],
            ];
        }

        return $out;
    }

    // ── private ───────────────────────────────────────────────────────────────

    private function append(string $sku, string $location, int $delta, string $note): void
    {
        $stmt = $this->db()->prepare('INSERT INTO stock_ledger (sku, location, delta, note) VALUES (?, ?, ?, ?)');
        $stmt->execute([$sku, $location, $delta, $note]);
    }

    private function requirePositive(int $qty): void
    {
        if ($qty < 1) {
            throw new \InvalidArgumentException('Quantity must be at least 1.');
        }
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
