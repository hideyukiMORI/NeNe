<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * KpiTracker — KPI / OKR metric tracking with target vs. actual comparison.
 *
 * Stores named KPI definitions (with optional targets) and accepts
 * time-series data-point recordings. Computes progress toward target
 * and supports period-scoped reporting.
 *
 * Distinct from:
 * - `CounterMetric` (simple increment/decrement counter)
 * - `ScoreBoard`    (high-score leaderboard)
 * - `BillingUsage`  (metered usage for billing)
 *
 * ## Usage
 *
 * ```php
 * $kpi = new KpiTracker($pdo);
 *
 * $id = $kpi->define('monthly-revenue', 'Monthly Revenue', 100000, '2026-05');
 * $kpi->record($id, 42000);
 * $kpi->record($id, 63000);
 * $kpi->progress($id); // ['actual' => 63000, 'target' => 100000, 'pct' => 63.0]
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE kpi_definitions (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     slug       VARCHAR(100) NOT NULL,
 *     label      TEXT         NOT NULL,
 *     target     INTEGER      NULL,
 *     period     VARCHAR(50)  NOT NULL DEFAULT '',
 *     unit       VARCHAR(50)  NULL,
 *     created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * CREATE TABLE kpi_data_points (
 *     id           INTEGER PRIMARY KEY AUTOINCREMENT,
 *     definition_id INTEGER NOT NULL,
 *     value         INTEGER NOT NULL,
 *     note          TEXT    NULL,
 *     recorded_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class KpiTracker
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Define a KPI with optional target and period.
     *
     * @param  int|null    $target Integer unit target (null = no target).
     * @param  string      $period Optional period tag (e.g. '2026-05', '2026-Q2', '').
     * @return int New definition ID.
     * @throws \InvalidArgumentException on empty slug or label.
     */
    public function define(
        string $slug,
        string $label,
        ?int $target = null,
        string $period = '',
        ?string $unit = null
    ): int {
        $slug  = trim($slug);
        $label = trim($label);
        if ($slug === '') {
            throw new \InvalidArgumentException('slug must not be empty.');
        }
        if ($label === '') {
            throw new \InvalidArgumentException('label must not be empty.');
        }

        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'INSERT INTO kpi_definitions (slug, label, target, period, unit, created_at)
             VALUES (:slug, :label, :target, :period, :unit, :now)'
        );
        $stmt->execute([
            ':slug'   => $slug,
            ':label'  => $label,
            ':target' => $target,
            ':period' => $period,
            ':unit'   => $unit,
            ':now'    => $now,
        ]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Retrieve a KPI definition by ID.
     *
     * @return array<string,mixed>|null
     */
    public function get(int $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM kpi_definitions WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Find a KPI definition by slug and period.
     *
     * @return array<string,mixed>|null
     */
    public function find(string $slug, string $period = ''): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM kpi_definitions WHERE slug = :slug AND period = :period LIMIT 1'
        );
        $stmt->execute([':slug' => trim($slug), ':period' => $period]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Record a data point for a KPI (stores latest value, not a delta).
     *
     * @return bool True on success; false if definition not found.
     */
    public function record(int $definitionId, int $value, ?string $note = null): bool
    {
        $def = $this->get($definitionId);
        if ($def === null) {
            return false;
        }

        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'INSERT INTO kpi_data_points (definition_id, value, note, recorded_at)
             VALUES (:did, :value, :note, :now)'
        );
        $stmt->execute([
            ':did'   => $definitionId,
            ':value' => $value,
            ':note'  => $note,
            ':now'   => $now,
        ]);
        return true;
    }

    /**
     * Latest recorded value for a KPI (most recent data point).
     *
     * @return int|null Null if no data points recorded yet.
     */
    public function latest(int $definitionId): ?int
    {
        $stmt = $this->db()->prepare(
            'SELECT value FROM kpi_data_points WHERE definition_id = :did
             ORDER BY recorded_at DESC, id DESC LIMIT 1'
        );
        $stmt->execute([':did' => $definitionId]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (int)$val : null;
    }

    /**
     * Progress summary: latest actual vs. target.
     *
     * @return array{actual: int|null, target: int|null, pct: float|null}
     */
    public function progress(int $definitionId): array
    {
        $def    = $this->get($definitionId);
        $actual = $this->latest($definitionId);
        $target = ($def !== null && $def['target'] !== null) ? (int)$def['target'] : null;
        $pct    = null;
        if ($actual !== null && $target !== null && $target > 0) {
            $pct = round((float)$actual / (float)$target * 100.0, 2);
        }
        return ['actual' => $actual, 'target' => $target, 'pct' => $pct];
    }

    /**
     * All data points for a KPI, ordered by time.
     *
     * @return list<array<string,mixed>>
     */
    public function history(int $definitionId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM kpi_data_points WHERE definition_id = :did
             ORDER BY recorded_at ASC, id ASC'
        );
        $stmt->execute([':did' => $definitionId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * All KPI definitions for a given period.
     *
     * @return list<array<string,mixed>>
     */
    public function forPeriod(string $period): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM kpi_definitions WHERE period = :period ORDER BY slug ASC'
        );
        $stmt->execute([':period' => $period]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Update target for a KPI definition.
     *
     * @return bool True if found and updated.
     */
    public function setTarget(int $definitionId, ?int $target): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE kpi_definitions SET target = :target WHERE id = :id'
        );
        $stmt->execute([':target' => $target, ':id' => $definitionId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Delete a KPI definition and all its data points.
     *
     * @return bool True if the definition existed.
     */
    public function delete(int $definitionId): bool
    {
        $def = $this->get($definitionId);
        if ($def === null) {
            return false;
        }
        $this->db()->prepare('DELETE FROM kpi_data_points WHERE definition_id = :did')
            ->execute([':did' => $definitionId]);
        $this->db()->prepare('DELETE FROM kpi_definitions WHERE id = :id')
            ->execute([':id' => $definitionId]);
        return true;
    }

    /**
     * Delete data points older than $cutoff for a definition.
     *
     * @return int Rows deleted.
     */
    public function purgeOlderThan(int $definitionId, string $cutoff): int
    {
        $stmt = $this->db()->prepare(
            'DELETE FROM kpi_data_points WHERE definition_id = :did AND recorded_at < :cutoff'
        );
        $stmt->execute([':did' => $definitionId, ':cutoff' => $cutoff]);
        return $stmt->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
