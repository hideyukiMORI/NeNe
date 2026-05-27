<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * Cross-driver upsert helper (MySQL + SQLite).
 *
 * Eliminates the repeated if/else driver-check boilerplate in Xion classes.
 *
 * ## Usage
 *
 * ```php
 * // Instead of:
 * $driver = $this->db()->getAttribute(PDO::ATTR_DRIVER_NAME);
 * if ($driver === 'mysql') {
 *     $stmt = $this->db()->prepare(
 *         'INSERT INTO presence (user_id, context, seen_at)
 *          VALUES (?, ?, CURRENT_TIMESTAMP)
 *          ON DUPLICATE KEY UPDATE seen_at = CURRENT_TIMESTAMP'
 *     );
 * } else {
 *     $stmt = $this->db()->prepare(
 *         'INSERT INTO presence (user_id, context, seen_at)
 *          VALUES (?, ?, CURRENT_TIMESTAMP)
 *          ON CONFLICT (user_id, context)
 *          DO UPDATE SET seen_at = CURRENT_TIMESTAMP'
 *     );
 * }
 * $stmt->execute([$userId, $context, ...]);
 *
 * // Write this instead:
 * DbUpsert::run(
 *     $this->db(),
 *     table: 'presence',
 *     data:  ['user_id' => $userId, 'context' => $context],
 *     conflictCols: ['user_id', 'context'],
 *     updateCols:   ['seen_at'],
 *     updateExprs:  ['seen_at' => 'CURRENT_TIMESTAMP'],
 * );
 * ```
 *
 * ## Parameters
 *
 * - `$data`        Column→value map for the INSERT.
 * - `$conflictCols` Unique columns that trigger the ON DUPLICATE / ON CONFLICT
 *                   (used for SQLite's ON CONFLICT clause; MySQL infers from the
 *                   unique key automatically).
 * - `$updateCols`  Columns to copy from the inserted row on conflict
 *                  (uses `VALUES(col)` / `excluded.col` syntax).
 * - `$updateExprs` Columns whose update value is a raw SQL expression, e.g.
 *                  `['updated_at' => 'CURRENT_TIMESTAMP']`.
 *                  These are appended after `$updateCols`.
 */
final class DbUpsert
{
    /**
     * Execute a cross-driver upsert.
     *
     * @param array<string,mixed>  $data         Column→value pairs for INSERT.
     * @param string[]             $conflictCols Unique columns (for SQLite ON CONFLICT clause).
     * @param string[]             $updateCols   Columns to mirror from inserted values on conflict.
     * @param array<string,string> $updateExprs  Column→raw-SQL-expression pairs appended to the SET clause.
     */
    public static function run(
        PDO $pdo,
        string $table,
        array $data,
        array $conflictCols,
        array $updateCols = [],
        array $updateExprs = [],
    ): void {
        $cols         = array_keys($data);
        $values       = array_values($data);
        $colList      = implode(', ', $cols);
        $placeholders = implode(', ', array_fill(0, count($cols), '?'));

        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'mysql') {
            $setParts = array_map(fn (string $c) => "{$c} = VALUES({$c})", $updateCols);

            foreach ($updateExprs as $col => $expr) {
                $setParts[] = "{$col} = {$expr}";
            }

            $setClause = implode(', ', $setParts);
            $sql       = "INSERT INTO {$table} ({$colList}) VALUES ({$placeholders})"
                       . ($setClause !== '' ? " ON DUPLICATE KEY UPDATE {$setClause}" : '');
        } else {
            $conflictList = implode(', ', $conflictCols);
            $setParts     = array_map(fn (string $c) => "{$c} = excluded.{$c}", $updateCols);

            foreach ($updateExprs as $col => $expr) {
                $setParts[] = "{$col} = {$expr}";
            }

            $setClause = implode(', ', $setParts);
            $sql       = "INSERT INTO {$table} ({$colList}) VALUES ({$placeholders})"
                       . ($setClause !== ''
                            ? " ON CONFLICT ({$conflictList}) DO UPDATE SET {$setClause}"
                            : " ON CONFLICT ({$conflictList}) DO NOTHING");
        }

        $pdo->prepare($sql)->execute($values);
    }
}
