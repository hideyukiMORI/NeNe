<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * AbTest — A/B test variant assignment and conversion tracking.
 *
 * Users are assigned to a variant once and the assignment is persisted.
 * Impressions and conversions are tracked per experiment and variant.
 *
 * ## Usage
 *
 * ```php
 * $ab = new AbTest($pdo);
 *
 * // Define variants for an experiment
 * $ab->define('checkout-button', ['control', 'green', 'red']);
 *
 * // Assign a user (deterministic once assigned)
 * $variant = $ab->assign('checkout-button', 'user-1');
 *
 * // Record an impression
 * $ab->impression('checkout-button', 'user-1');
 *
 * // Record a conversion
 * $ab->convert('checkout-button', 'user-1');
 *
 * // Get results
 * $ab->results('checkout-button');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE ab_experiments (
 *     experiment VARCHAR(100) NOT NULL PRIMARY KEY,
 *     variants   TEXT         NOT NULL DEFAULT '[]',
 *     created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 *
 * CREATE TABLE ab_assignments (
 *     experiment VARCHAR(100) NOT NULL,
 *     user_id    VARCHAR(255) NOT NULL,
 *     variant    VARCHAR(100) NOT NULL,
 *     assigned_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     PRIMARY KEY (experiment, user_id)
 * );
 *
 * CREATE TABLE ab_events (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     experiment VARCHAR(100) NOT NULL,
 *     variant    VARCHAR(100) NOT NULL,
 *     event_type VARCHAR(20)  NOT NULL,
 *     user_id    VARCHAR(255) NOT NULL DEFAULT '',
 *     created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class AbTest
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Define (or update) the variants for an experiment.
     *
     * @param  list<string> $variants Non-empty list of variant names.
     * @throws \InvalidArgumentException if experiment is empty or variants is empty.
     */
    public function define(string $experiment, array $variants): void
    {
        $experiment = $this->validateExperiment($experiment);
        if (empty($variants)) {
            throw new \InvalidArgumentException('variants must not be empty.');
        }

        $db     = $this->db();
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $db->prepare(
                'INSERT INTO ab_experiments (experiment, variants)
                 VALUES (:exp, :variants)
                 ON CONFLICT (experiment)
                 DO UPDATE SET variants = excluded.variants'
            )->execute([':exp' => $experiment, ':variants' => json_encode(array_values($variants), JSON_THROW_ON_ERROR)]);
        } else {
            $db->prepare(
                'INSERT INTO ab_experiments (experiment, variants)
                 VALUES (:exp, :variants)
                 ON DUPLICATE KEY UPDATE variants = VALUES(variants)'
            )->execute([':exp' => $experiment, ':variants' => json_encode(array_values($variants), JSON_THROW_ON_ERROR)]);
        }
    }

    /**
     * Assign a user to a variant (idempotent — returns the same variant on repeat calls).
     *
     * Variant is chosen by hashing (experiment + user_id) for even distribution.
     *
     * @return string The assigned variant name.
     * @throws \InvalidArgumentException if experiment is not defined.
     */
    public function assign(string $experiment, string $userId): string
    {
        $experiment = $this->validateExperiment($experiment);
        $userId     = trim($userId);

        $db     = $this->db();
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);

        // Check for existing assignment
        $stmt = $db->prepare(
            'SELECT variant FROM ab_assignments WHERE experiment = :exp AND user_id = :uid LIMIT 1'
        );
        $stmt->execute([':exp' => $experiment, ':uid' => $userId]);
        $existing = $stmt->fetchColumn();
        if ($existing !== false) {
            return (string)$existing;
        }

        // Load variants
        $variants = $this->loadVariants($experiment);
        if (empty($variants)) {
            throw new \InvalidArgumentException("Experiment '{$experiment}' is not defined.");
        }

        // Deterministic assignment via hash
        $index   = abs(crc32($experiment . $userId)) % count($variants);
        $variant = $variants[$index];

        if ($driver === 'sqlite') {
            $db->prepare(
                'INSERT INTO ab_assignments (experiment, user_id, variant)
                 VALUES (:exp, :uid, :var)
                 ON CONFLICT (experiment, user_id) DO NOTHING'
            )->execute([':exp' => $experiment, ':uid' => $userId, ':var' => $variant]);
        } else {
            $db->prepare(
                'INSERT IGNORE INTO ab_assignments (experiment, user_id, variant)
                 VALUES (:exp, :uid, :var)'
            )->execute([':exp' => $experiment, ':uid' => $userId, ':var' => $variant]);
        }

        return $variant;
    }

    /**
     * Get the variant assigned to a user (null if not yet assigned).
     */
    public function getVariant(string $experiment, string $userId): ?string
    {
        $stmt = $this->db()->prepare(
            'SELECT variant FROM ab_assignments WHERE experiment = :exp AND user_id = :uid LIMIT 1'
        );
        $stmt->execute([':exp' => $experiment, ':uid' => $userId]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (string)$val : null;
    }

    /**
     * Record an impression for a user's assigned variant.
     */
    public function impression(string $experiment, string $userId): void
    {
        $variant = $this->getVariant($experiment, $userId);
        if ($variant === null) {
            return;
        }
        $this->track($experiment, $variant, 'impression', $userId);
    }

    /**
     * Record a conversion for a user's assigned variant.
     */
    public function convert(string $experiment, string $userId): void
    {
        $variant = $this->getVariant($experiment, $userId);
        if ($variant === null) {
            return;
        }
        $this->track($experiment, $variant, 'conversion', $userId);
    }

    /**
     * Get results for an experiment: impressions and conversions per variant.
     *
     * @return array<string, array{impressions: int, conversions: int, rate: float}>
     */
    public function results(string $experiment): array
    {
        $experiment = $this->validateExperiment($experiment);
        $variants   = $this->loadVariants($experiment);

        $stmt = $this->db()->prepare(
            'SELECT variant, event_type, COUNT(*) AS cnt
             FROM ab_events
             WHERE experiment = :exp
             GROUP BY variant, event_type'
        );
        $stmt->execute([':exp' => $experiment]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $data = [];
        foreach ($variants as $v) {
            $data[$v] = ['impressions' => 0, 'conversions' => 0, 'rate' => 0.0];
        }

        foreach ($rows as $row) {
            $v    = (string)$row['variant'];
            $type = (string)$row['event_type'];
            $cnt  = (int)$row['cnt'];
            if (!isset($data[$v])) {
                $data[$v] = ['impressions' => 0, 'conversions' => 0, 'rate' => 0.0];
            }
            if ($type === 'impression') {
                $data[$v]['impressions'] = $cnt;
            } elseif ($type === 'conversion') {
                $data[$v]['conversions'] = $cnt;
            }
        }

        foreach ($data as $v => $stats) {
            $data[$v]['rate'] = $stats['impressions'] > 0
                ? round($stats['conversions'] / $stats['impressions'], 4)
                : 0.0;
        }

        return $data;
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    private function validateExperiment(string $experiment): string
    {
        $experiment = trim($experiment);
        if ($experiment === '') {
            throw new \InvalidArgumentException('experiment must not be empty.');
        }
        return $experiment;
    }

    /**
     * @return list<string>
     */
    private function loadVariants(string $experiment): array
    {
        $stmt = $this->db()->prepare(
            'SELECT variants FROM ab_experiments WHERE experiment = :exp LIMIT 1'
        );
        $stmt->execute([':exp' => $experiment]);
        $val = $stmt->fetchColumn();
        if ($val === false) {
            return [];
        }
        return json_decode((string)$val, true) ?? [];
    }

    private function track(string $experiment, string $variant, string $eventType, string $userId): void
    {
        $this->db()->prepare(
            'INSERT INTO ab_events (experiment, variant, event_type, user_id)
             VALUES (:exp, :var, :type, :uid)'
        )->execute([':exp' => $experiment, ':var' => $variant, ':type' => $eventType, ':uid' => $userId]);
    }
}
