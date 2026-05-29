<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\DbUpsert;
use Nene\Xion\PdoConnection;
use PDO;

/**
 * PercentageRollout — gradual feature rollout by percentage with sticky bucketing.
 *
 * Enables a feature for a deterministic percentage of keys (user ids, account
 * ids, …). Distinct from `FeatureFlag` (FT124, a global on/off toggle): here a
 * flag carries a 0–100 percentage and membership is decided by hashing the
 * `(flag, key)` pair, so:
 *
 * - the same key always gets the same answer for a given percentage (sticky —
 *   no flicker between requests), and
 * - raising the percentage only *adds* keys (a key enabled at 20% stays
 *   enabled at 50%), because the bucket is derived from the key, not random.
 *
 * ## Usage
 *
 * ```php
 * $ro = new PercentageRollout($pdo);
 *
 * $ro->setPercentage('new_ui', 25);       // 25% rollout
 * $ro->isEnabled('new_ui', 'user-42');    // deterministic true/false
 * $ro->percentageFor('new_ui');           // 25
 *
 * $ro->enableFully('new_ui');             // 100% — everyone
 * $ro->disable('new_ui');                 // 0% — no one
 * $ro->remove('new_ui');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE rollout_flags (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     flag       VARCHAR(100) NOT NULL,
 *     percentage INTEGER      NOT NULL DEFAULT 0,
 *     updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (flag)
 * );
 * ```
 */
final class PercentageRollout
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Set the rollout percentage for a flag (0–100). Idempotent per flag.
     *
     * @param  string $flag    Flag name.
     * @param  int    $percent Rollout percentage, 0–100 inclusive.
     * @throws \InvalidArgumentException on empty flag or out-of-range percent.
     */
    public function setPercentage(string $flag, int $percent): void
    {
        $flag = $this->validateFlag($flag);
        if ($percent < 0 || $percent > 100) {
            throw new \InvalidArgumentException('Percentage must be between 0 and 100.');
        }

        DbUpsert::run(
            $this->db(),
            table:        'rollout_flags',
            data:         ['flag' => $flag, 'percentage' => $percent],
            conflictCols: ['flag'],
            updateCols:   ['percentage'],
            updateExprs:  ['updated_at' => 'CURRENT_TIMESTAMP'],
        );
    }

    /**
     * Return the rollout percentage for a flag (0 if not configured).
     */
    public function percentageFor(string $flag): int
    {
        $flag = $this->validateFlag($flag);
        $stmt = $this->db()->prepare('SELECT percentage FROM rollout_flags WHERE flag = ?');
        $stmt->execute([$flag]);
        $pct = $stmt->fetchColumn();

        return $pct === false ? 0 : (int)$pct;
    }

    /**
     * Whether the feature is enabled for a given key under the current percentage.
     *
     * Deterministic and sticky: depends only on `(flag, key)` and the stored
     * percentage. A key in the enabled set at percentage P stays enabled for
     * any P' >= P.
     *
     * @param string $flag Flag name.
     * @param string $key  Stable identity (e.g. user id).
     */
    public function isEnabled(string $flag, string $key): bool
    {
        $pct = $this->percentageFor($flag);
        if ($pct <= 0) {
            return false;
        }
        if ($pct >= 100) {
            return true;
        }

        return $this->bucket($flag, $key) < $pct;
    }

    /**
     * Set the flag to 100%.
     */
    public function enableFully(string $flag): void
    {
        $this->setPercentage($flag, 100);
    }

    /**
     * Set the flag to 0%.
     */
    public function disable(string $flag): void
    {
        $this->setPercentage($flag, 0);
    }

    /**
     * Remove a flag entirely (subsequent `isEnabled` is false). No-op if absent.
     */
    public function remove(string $flag): void
    {
        $flag = $this->validateFlag($flag);
        $stmt = $this->db()->prepare('DELETE FROM rollout_flags WHERE flag = ?');
        $stmt->execute([$flag]);
    }

    /**
     * List all flags and their percentages, ordered by flag name.
     *
     * @return array<int,array{flag:string,percentage:int}>
     */
    public function flags(): array
    {
        $stmt = $this->db()->query('SELECT flag, percentage FROM rollout_flags ORDER BY flag ASC');
        $rows = $stmt === false ? [] : $stmt->fetchAll(PDO::FETCH_ASSOC);

        $out = [];
        /** @var array<string,mixed> $row */
        foreach ($rows as $row) {
            $out[] = ['flag' => (string)$row['flag'], 'percentage' => (int)$row['percentage']];
        }

        return $out;
    }

    // ── private ───────────────────────────────────────────────────────────────

    /**
     * Map (flag, key) to a stable bucket in 0–99.
     */
    private function bucket(string $flag, string $key): int
    {
        return crc32($flag . ':' . $key) % 100;
    }

    private function validateFlag(string $flag): string
    {
        $flag = trim($flag);
        if ($flag === '') {
            throw new \InvalidArgumentException('Flag must not be empty.');
        }

        return $flag;
    }

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
