<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\DbUpsert;
use Nene\Xion\PdoConnection;
use PDO;

/**
 * DataRetention — central table→TTL retention policy registry and purge driver.
 *
 * Many Xion helpers ship their own `purgeOlderThan($days)` method; this class
 * centralises *how long* each table is kept so a single retention cron can
 * drive them. It records a TTL (in days) per table, computes the cutoff
 * timestamp, and can execute the delete itself for tables with a standard
 * timestamp column.
 *
 * The table name and date-column name are **developer-supplied identifiers**
 * (from the policy registry / calling code), never end-user input. They are
 * validated against a strict `^[A-Za-z_][A-Za-z0-9_]*$` pattern before being
 * interpolated into SQL, since identifiers cannot be bound as parameters.
 *
 * ## Usage
 *
 * ```php
 * $ret = new DataRetention($pdo);
 *
 * $ret->setPolicy('access_logs', 90);   // keep 90 days
 * $ret->setPolicy('page_views', 30);
 *
 * $ret->policyFor('access_logs');        // 90
 * $ret->policies();                      // [['table'=>'access_logs','ttlDays'=>90], ...]
 *
 * // For a retention cron: what should be purged, and to which cutoff?
 * foreach ($ret->due() as $p) {
 *     // $p = ['table'=>'access_logs','ttlDays'=>90,'cutoff'=>'2026-03-01 00:00:00']
 * }
 *
 * // Execute the delete for a table with a timestamp column
 * $deleted = $ret->purge('access_logs', 'created_at'); // rows removed
 *
 * $ret->removePolicy('page_views');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE retention_policies (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     table_name VARCHAR(100) NOT NULL,
 *     ttl_days   INTEGER      NOT NULL,
 *     updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (table_name)
 * );
 * ```
 */
final class DataRetention
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── policy management ───────────────────────────────────────────────────────

    /**
     * Set (or update) the retention TTL for a table. Idempotent per table.
     *
     * @param  string $table   Table name (identifier).
     * @param  int    $ttlDays Days to retain (must be >= 1).
     * @throws \InvalidArgumentException on bad identifier or $ttlDays < 1.
     */
    public function setPolicy(string $table, int $ttlDays): void
    {
        $table = $this->assertIdentifier($table);
        if ($ttlDays < 1) {
            throw new \InvalidArgumentException('TTL must be at least 1 day.');
        }

        DbUpsert::run(
            $this->db(),
            table:        'retention_policies',
            data:         ['table_name' => $table, 'ttl_days' => $ttlDays],
            conflictCols: ['table_name'],
            updateCols:   ['ttl_days'],
            updateExprs:  ['updated_at' => 'CURRENT_TIMESTAMP'],
        );
    }

    /**
     * Return the TTL (days) for a table, or null if no policy is set.
     */
    public function policyFor(string $table): ?int
    {
        $table = $this->assertIdentifier($table);

        $stmt = $this->db()->prepare('SELECT ttl_days FROM retention_policies WHERE table_name = ?');
        $stmt->execute([$table]);
        $ttl = $stmt->fetchColumn();

        return $ttl === false ? null : (int)$ttl;
    }

    /**
     * Remove a table's policy. No-op if absent.
     */
    public function removePolicy(string $table): void
    {
        $table = $this->assertIdentifier($table);
        $stmt  = $this->db()->prepare('DELETE FROM retention_policies WHERE table_name = ?');
        $stmt->execute([$table]);
    }

    /**
     * List all policies, ordered by table name.
     *
     * @return array<int,array{table:string,ttlDays:int}>
     */
    public function policies(): array
    {
        $stmt = $this->db()->query('SELECT table_name, ttl_days FROM retention_policies ORDER BY table_name ASC');
        $rows = $stmt === false ? [] : $stmt->fetchAll(PDO::FETCH_ASSOC);

        $out = [];
        /** @var array<string,mixed> $row */
        foreach ($rows as $row) {
            $out[] = ['table' => (string)$row['table_name'], 'ttlDays' => (int)$row['ttl_days']];
        }

        return $out;
    }

    // ── cutoff / purge ──────────────────────────────────────────────────────────

    /**
     * Compute the cutoff timestamp for a table: rows older than this are stale.
     *
     * @param  string      $table Table name (identifier).
     * @param  string|null $asOf  Reference 'Y-m-d H:i:s' (or 'Y-m-d'); defaults to now.
     * @return string|null        Cutoff as 'Y-m-d H:i:s', or null if no policy.
     */
    public function cutoff(string $table, ?string $asOf = null): ?string
    {
        $ttl = $this->policyFor($table);
        if ($ttl === null) {
            return null;
        }

        return $this->cutoffFor($ttl, $asOf);
    }

    /**
     * Return every policy with its computed cutoff, for a retention cron to act on.
     *
     * @param  string|null $asOf Reference timestamp; defaults to now.
     * @return array<int,array{table:string,ttlDays:int,cutoff:string}>
     */
    public function due(?string $asOf = null): array
    {
        $out = [];
        foreach ($this->policies() as $p) {
            $out[] = [
                'table'   => $p['table'],
                'ttlDays' => $p['ttlDays'],
                'cutoff'  => $this->cutoffFor($p['ttlDays'], $asOf),
            ];
        }

        return $out;
    }

    /**
     * Delete rows older than the table's TTL by a timestamp column.
     *
     * @param  string      $table      Table to purge (identifier).
     * @param  string      $dateColumn Timestamp column to compare (identifier).
     * @param  string|null $asOf       Reference timestamp; defaults to now.
     * @return int                     Number of rows deleted.
     * @throws \InvalidArgumentException on bad identifier or missing policy.
     */
    public function purge(string $table, string $dateColumn, ?string $asOf = null): int
    {
        $table  = $this->assertIdentifier($table);
        $column = $this->assertIdentifier($dateColumn);
        $cutoff = $this->cutoff($table, $asOf);
        if ($cutoff === null) {
            throw new \InvalidArgumentException("No retention policy set for table: {$table}");
        }

        $stmt = $this->db()->prepare("DELETE FROM {$table} WHERE {$column} < ?");
        $stmt->execute([$cutoff]);

        return $stmt->rowCount();
    }

    // ── private ───────────────────────────────────────────────────────────────

    private function cutoffFor(int $ttlDays, ?string $asOf): string
    {
        $base = $asOf === null
            ? new \DateTimeImmutable()
            : $this->parseTimestamp($asOf);

        return $base->sub(new \DateInterval("P{$ttlDays}D"))->format('Y-m-d H:i:s');
    }

    private function parseTimestamp(string $value): \DateTimeImmutable
    {
        $ts = strtotime($value);
        if ($ts === false) {
            throw new \InvalidArgumentException("Invalid timestamp: {$value}");
        }

        return (new \DateTimeImmutable())->setTimestamp($ts);
    }

    private function assertIdentifier(string $name): string
    {
        $name = trim($name);
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new \InvalidArgumentException("Invalid SQL identifier: {$name}");
        }

        return $name;
    }

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
