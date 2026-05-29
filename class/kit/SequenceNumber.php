<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\DbUpsert;
use Nene\Xion\PdoConnection;
use PDO;

/**
 * SequenceNumber — gapless sequential numbering per named scope.
 *
 * Hands out monotonically increasing integers for a named scope using an
 * atomic row-level increment, so concurrent callers never receive the same
 * number and the sequence has no gaps. Useful for invoice numbers, order
 * numbers, ticket numbers, and any human-facing identifier that must be
 * sequential and unique rather than a random token or a raw auto-increment
 * primary key.
 *
 * Unlike a database AUTOINCREMENT column, the counter is independent of any
 * table's row lifecycle (deleting rows never burns numbers) and several
 * independent sequences can live side by side, keyed by `scope`.
 *
 * ## Usage
 *
 * ```php
 * $seq = new SequenceNumber($pdo);
 *
 * // Atomic next value (1, 2, 3, ... per scope)
 * $seq->next('invoice');                 // 1
 * $seq->next('invoice');                 // 2
 * $seq->next('order');                   // 1  (independent scope)
 *
 * // Formatted, zero-padded, with a prefix
 * $seq->formatted('invoice', 'INV-');    // 'INV-000003'
 * $seq->formatted('invoice', 'INV-2026-', 4); // 'INV-2026-0004'
 *
 * // Inspect without consuming a number
 * $seq->peek('invoice');                 // 3
 *
 * // Administrative reset (e.g. yearly roll-over)
 * $seq->reset('invoice');                // back to 0 → next() returns 1
 * $seq->reset('invoice', 1000);          // next() returns 1001
 * ```
 *
 * ## Concurrency
 *
 * `next()` performs the upsert+increment and the read inside a single
 * transaction. The `current_value = current_value + 1` update takes a
 * row-level write lock, so simultaneous callers serialise and each observes
 * its own distinct value. If a transaction is already open, the existing one
 * is reused rather than nested.
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE sequence_numbers (
 *     id            INTEGER PRIMARY KEY AUTOINCREMENT,
 *     scope         VARCHAR(100) NOT NULL,
 *     current_value BIGINT       NOT NULL DEFAULT 0,
 *     updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (scope)
 * );
 * ```
 */
final class SequenceNumber
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Atomically consume and return the next value for a scope.
     *
     * The first call for a previously unseen scope returns 1.
     *
     * @param  string $scope Sequence name (e.g. 'invoice').
     * @return int           The newly assigned value (>= 1).
     * @throws \InvalidArgumentException if $scope is empty.
     */
    public function next(string $scope): int
    {
        $scope = $this->validateScope($scope);
        $db    = $this->db();

        $ownTransaction = !$db->inTransaction();
        if ($ownTransaction) {
            $db->beginTransaction();
        }

        try {
            DbUpsert::run(
                $db,
                table:        'sequence_numbers',
                data:         ['scope' => $scope, 'current_value' => 1],
                conflictCols: ['scope'],
                updateExprs:  [
                    'current_value' => 'current_value + 1',
                    'updated_at'    => 'CURRENT_TIMESTAMP',
                ],
            );

            $stmt = $db->prepare('SELECT current_value FROM sequence_numbers WHERE scope = ?');
            $stmt->execute([$scope]);
            $value = (int)$stmt->fetchColumn();

            if ($ownTransaction) {
                $db->commit();
            }

            return $value;
        } catch (\Throwable $e) {
            if ($ownTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Consume the next value and return it formatted with a prefix and padding.
     *
     * @param  string $scope  Sequence name.
     * @param  string $prefix Literal prefix prepended to the padded number.
     * @param  int    $pad    Minimum digit width, left-padded with zeros (>= 1).
     * @return string         e.g. 'INV-000042'.
     * @throws \InvalidArgumentException if $scope is empty or $pad < 1.
     */
    public function formatted(string $scope, string $prefix = '', int $pad = 6): string
    {
        if ($pad < 1) {
            throw new \InvalidArgumentException('Pad width must be at least 1.');
        }

        $value = $this->next($scope);

        return $prefix . str_pad((string)$value, $pad, '0', STR_PAD_LEFT);
    }

    /**
     * Return the current value for a scope without consuming a number.
     *
     * @param  string $scope Sequence name.
     * @return int           Current value, or 0 if the scope has never been used.
     * @throws \InvalidArgumentException if $scope is empty.
     */
    public function peek(string $scope): int
    {
        $scope = $this->validateScope($scope);

        $stmt = $this->db()->prepare('SELECT current_value FROM sequence_numbers WHERE scope = ?');
        $stmt->execute([$scope]);
        $value = $stmt->fetchColumn();

        return $value === false ? 0 : (int)$value;
    }

    /**
     * Reset a scope's counter to a fixed value (default 0).
     *
     * After `reset($scope, $to)`, the next `next()` returns `$to + 1`.
     *
     * @param  string $scope Sequence name.
     * @param  int    $to    Value to reset the counter to (>= 0).
     * @throws \InvalidArgumentException if $scope is empty or $to < 0.
     */
    public function reset(string $scope, int $to = 0): void
    {
        $scope = $this->validateScope($scope);
        if ($to < 0) {
            throw new \InvalidArgumentException('Reset value must not be negative.');
        }

        DbUpsert::run(
            $this->db(),
            table:        'sequence_numbers',
            data:         ['scope' => $scope, 'current_value' => $to],
            conflictCols: ['scope'],
            updateCols:   ['current_value'],
            updateExprs:  ['updated_at' => 'CURRENT_TIMESTAMP'],
        );
    }

    // ── private ───────────────────────────────────────────────────────────────

    private function validateScope(string $scope): string
    {
        $scope = trim($scope);
        if ($scope === '') {
            throw new \InvalidArgumentException('Scope must not be empty.');
        }

        return $scope;
    }

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
