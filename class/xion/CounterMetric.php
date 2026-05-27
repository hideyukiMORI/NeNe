<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * CounterMetric — named metric counters with daily/hourly bucket aggregation.
 *
 * Increments named counters and stores daily and hourly time-series buckets.
 * Useful for page-view counts, event rates, feature usage analytics, and
 * dashboard sparklines without an external metrics backend.
 *
 * ## Usage
 *
 * ```php
 * $cm = new CounterMetric($pdo);
 *
 * // Increment on every event
 * $cm->increment('page.views');
 * $cm->increment('api.calls', 'GET:/users', 5); // add 5
 *
 * // Totals
 * $cm->total('page.views');          // all time
 * $cm->total('page.views', '-7 days'); // last 7 days
 *
 * // Time series (daily buckets)
 * $cm->dailySeries('page.views', 7); // last 7 days
 * $cm->hourlySeries('api.calls', 24); // last 24 hours
 *
 * // Reset / purge
 * $cm->reset('page.views');
 * $cm->purgeOlderThan(90);
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE counter_metrics (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     metric     VARCHAR(100) NOT NULL,
 *     dimension  VARCHAR(255) NOT NULL DEFAULT '',
 *     bucket_day CHAR(10)     NOT NULL,
 *     bucket_hour TINYINT     NOT NULL DEFAULT 0,
 *     value      BIGINT       NOT NULL DEFAULT 0,
 *     UNIQUE (metric, dimension, bucket_day, bucket_hour)
 * );
 * ```
 */
final class CounterMetric
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Increment a counter.
     *
     * @param  string $metric     Metric name (e.g. 'page.views').
     * @param  string $dimension  Optional dimension/label (e.g. endpoint path).
     * @param  int    $by         Amount to increment (default 1).
     * @throws \InvalidArgumentException if metric is empty or $by < 1.
     */
    public function increment(string $metric, string $dimension = '', int $by = 1): void
    {
        $metric = $this->validateMetric($metric);
        if ($by < 1) {
            throw new \InvalidArgumentException('Increment amount must be at least 1.');
        }

        $now  = new \DateTimeImmutable();
        $day  = $now->format('Y-m-d');
        $hour = (int)$now->format('G');
        $db   = $this->db();

        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $db->prepare(
                'INSERT INTO counter_metrics (metric, dimension, bucket_day, bucket_hour, value)
                 VALUES (:metric, :dim, :day, :hour, :by)
                 ON CONFLICT (metric, dimension, bucket_day, bucket_hour)
                 DO UPDATE SET value = value + excluded.value'
            )->execute([':metric' => $metric, ':dim' => $dimension, ':day' => $day, ':hour' => $hour, ':by' => $by]);
        } else {
            $db->prepare(
                'INSERT INTO counter_metrics (metric, dimension, bucket_day, bucket_hour, value)
                 VALUES (:metric, :dim, :day, :hour, :by)
                 ON DUPLICATE KEY UPDATE value = value + VALUES(value)'
            )->execute([':metric' => $metric, ':dim' => $dimension, ':day' => $day, ':hour' => $hour, ':by' => $by]);
        }
    }

    /**
     * Get the total counter value for a metric, optionally filtered by dimension and time.
     *
     * @param string|null $since     Datetime string (e.g. '-7 days'). Null = all time.
     * @param string      $dimension Dimension filter. Empty = all dimensions summed.
     */
    public function total(string $metric, ?string $since = null, string $dimension = ''): int
    {
        $metric = $this->validateMetric($metric);
        $params = [':metric' => $metric];
        $where  = 'metric = :metric';

        if ($dimension !== '') {
            $where         .= ' AND dimension = :dim';
            $params[':dim'] = $dimension;
        }
        if ($since !== null) {
            $cutoff             = (new \DateTimeImmutable($since))->format('Y-m-d');
            $where             .= ' AND bucket_day >= :since';
            $params[':since']   = $cutoff;
        }

        $stmt = $this->db()->prepare("SELECT COALESCE(SUM(value), 0) FROM counter_metrics WHERE {$where}");
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Get daily bucket totals for the last N days, oldest first.
     *
     * @return list<array{date: string, value: int}>
     */
    public function dailySeries(string $metric, int $days = 30, string $dimension = ''): array
    {
        $metric = $this->validateMetric($metric);
        $since  = (new \DateTimeImmutable())->modify("-{$days} days")->format('Y-m-d');
        $params = [':metric' => $metric, ':since' => $since];
        $where  = 'metric = :metric AND bucket_day >= :since';

        if ($dimension !== '') {
            $where         .= ' AND dimension = :dim';
            $params[':dim'] = $dimension;
        }

        $stmt = $this->db()->prepare(
            "SELECT bucket_day AS date, SUM(value) AS value
             FROM counter_metrics WHERE {$where}
             GROUP BY bucket_day ORDER BY bucket_day ASC"
        );
        $stmt->execute($params);
        return array_map(
            static fn (array $r) => ['date' => (string)$r['date'], 'value' => (int)$r['value']],
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * Get hourly bucket totals for the last N hours, oldest first.
     *
     * @return list<array{day: string, hour: int, value: int}>
     */
    public function hourlySeries(string $metric, int $hours = 24, string $dimension = ''): array
    {
        $metric = $this->validateMetric($metric);
        $since  = (new \DateTimeImmutable())->modify("-{$hours} hours")->format('Y-m-d');
        $params = [':metric' => $metric, ':since' => $since];
        $where  = 'metric = :metric AND bucket_day >= :since';

        if ($dimension !== '') {
            $where         .= ' AND dimension = :dim';
            $params[':dim'] = $dimension;
        }

        $stmt = $this->db()->prepare(
            "SELECT bucket_day AS day, bucket_hour AS hour, SUM(value) AS value
             FROM counter_metrics WHERE {$where}
             GROUP BY bucket_day, bucket_hour ORDER BY bucket_day ASC, bucket_hour ASC"
        );
        $stmt->execute($params);
        return array_map(
            static fn (array $r) => ['day' => (string)$r['day'], 'hour' => (int)$r['hour'], 'value' => (int)$r['value']],
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * Reset (delete) all buckets for a metric.
     *
     * @return int Number of rows deleted.
     */
    public function reset(string $metric, string $dimension = ''): int
    {
        $metric = $this->validateMetric($metric);
        if ($dimension !== '') {
            $stmt = $this->db()->prepare(
                'DELETE FROM counter_metrics WHERE metric = :metric AND dimension = :dim'
            );
            $stmt->execute([':metric' => $metric, ':dim' => $dimension]);
        } else {
            $stmt = $this->db()->prepare('DELETE FROM counter_metrics WHERE metric = :metric');
            $stmt->execute([':metric' => $metric]);
        }
        return $stmt->rowCount();
    }

    /**
     * Delete bucket rows older than N days (maintenance).
     *
     * @return int Number of rows deleted.
     */
    public function purgeOlderThan(int $days): int
    {
        $cutoff = (new \DateTimeImmutable())->modify("-{$days} days")->format('Y-m-d');
        $stmt   = $this->db()->prepare('DELETE FROM counter_metrics WHERE bucket_day < :cutoff');
        $stmt->execute([':cutoff' => $cutoff]);
        return $stmt->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    private function validateMetric(string $metric): string
    {
        $metric = trim($metric);
        if ($metric === '') {
            throw new \InvalidArgumentException('metric must not be empty.');
        }
        return $metric;
    }
}
