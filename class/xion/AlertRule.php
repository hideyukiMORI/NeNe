<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * AlertRule — metric-based alert rules with threshold evaluation and event log.
 *
 * Define named rules with a metric name, numeric threshold, and comparison
 * condition. When a metric value is evaluated against a rule, a breach triggers
 * an alert event. Useful for monitoring, SLA enforcement, and business KPI alerts.
 *
 * ## Supported conditions
 *
 * - `'gt'`  — value > threshold
 * - `'gte'` — value ≥ threshold
 * - `'lt'`  — value < threshold
 * - `'lte'` — value ≤ threshold
 * - `'eq'`  — value == threshold
 *
 * ## Usage
 *
 * ```php
 * $ar = new AlertRule($pdo);
 *
 * // Define a rule
 * $id = $ar->define('cpu-high', 'cpu_percent', 90.0, 'gte');
 *
 * // Evaluate (returns true and logs an event if threshold breached)
 * $ar->evaluate('cpu-high', 95.2);   // true → alert!
 * $ar->evaluate('cpu-high', 50.0);   // false → no alert
 *
 * // Query
 * $ar->history('cpu-high', 20);
 * $ar->find('cpu-high');
 * $ar->all();
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE alert_rules (
 *     id                INTEGER PRIMARY KEY AUTOINCREMENT,
 *     name              VARCHAR(255) NOT NULL UNIQUE,
 *     metric            VARCHAR(255) NOT NULL,
 *     threshold         DOUBLE       NOT NULL DEFAULT 0,
 *     condition_op      VARCHAR(10)  NOT NULL DEFAULT 'gt',
 *     enabled           TINYINT(1)   NOT NULL DEFAULT 1,
 *     last_triggered_at DATETIME     DEFAULT NULL,
 *     created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 *
 * CREATE TABLE alert_events (
 *     id           INTEGER PRIMARY KEY AUTOINCREMENT,
 *     rule_id      INTEGER NOT NULL,
 *     metric_value DOUBLE  NOT NULL,
 *     triggered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class AlertRule
{
    private const VALID_OPS = ['gt', 'gte', 'lt', 'lte', 'eq'];

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Define (or replace) an alert rule.
     *
     * @param string $condition One of: 'gt', 'gte', 'lt', 'lte', 'eq'.
     * @return int The rule ID.
     * @throws \InvalidArgumentException on validation failure.
     */
    public function define(string $name, string $metric, float $threshold, string $condition = 'gt'): int
    {
        $name      = $this->validateName($name);
        $metric    = trim($metric);
        $condition = strtolower(trim($condition));
        if ($metric === '') {
            throw new \InvalidArgumentException('metric must not be empty.');
        }
        if (!in_array($condition, self::VALID_OPS, true)) {
            throw new \InvalidArgumentException(
                'condition must be one of: ' . implode(', ', self::VALID_OPS) . '.'
            );
        }

        $db = $this->db();
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $db->prepare(
                'INSERT INTO alert_rules (name, metric, threshold, condition_op)
                 VALUES (:name, :metric, :thr, :op)
                 ON CONFLICT (name)
                 DO UPDATE SET metric       = excluded.metric,
                               threshold    = excluded.threshold,
                               condition_op = excluded.condition_op'
            )->execute([':name' => $name, ':metric' => $metric, ':thr' => $threshold, ':op' => $condition]);
        } else {
            $db->prepare(
                'INSERT INTO alert_rules (name, metric, threshold, condition_op)
                 VALUES (:name, :metric, :thr, :op)
                 ON DUPLICATE KEY UPDATE metric       = VALUES(metric),
                                         threshold    = VALUES(threshold),
                                         condition_op = VALUES(condition_op)'
            )->execute([':name' => $name, ':metric' => $metric, ':thr' => $threshold, ':op' => $condition]);
        }

        $stmt = $db->prepare('SELECT id FROM alert_rules WHERE name = :name LIMIT 1');
        $stmt->execute([':name' => $name]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Enable a rule.
     *
     * @return bool True if the rule exists.
     */
    public function enable(string $name): bool
    {
        return $this->setEnabled($name, true);
    }

    /**
     * Disable a rule (evaluate() will always return false for disabled rules).
     *
     * @return bool True if the rule exists.
     */
    public function disable(string $name): bool
    {
        return $this->setEnabled($name, false);
    }

    /**
     * Evaluate a metric value against a named rule.
     *
     * Returns true if the rule is enabled and the threshold condition is breached.
     * A breach logs an alert event and updates last_triggered_at.
     *
     * Returns false if:
     * - the rule does not exist
     * - the rule is disabled
     * - the condition is not met
     *
     * @throws \InvalidArgumentException if name is empty.
     */
    public function evaluate(string $name, float $value): bool
    {
        $name = $this->validateName($name);
        $stmt = $this->db()->prepare(
            'SELECT id, threshold, condition_op, enabled
             FROM alert_rules WHERE name = :name LIMIT 1'
        );
        $stmt->execute([':name' => $name]);
        $rule = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($rule === false || (int)$rule['enabled'] !== 1) {
            return false;
        }

        $threshold = (float)$rule['threshold'];
        $breach    = match ($rule['condition_op']) {
            'gt'    => $value > $threshold,
            'gte'   => $value >= $threshold,
            'lt'    => $value < $threshold,
            'lte'   => $value <= $threshold,
            'eq'    => abs($value - $threshold) < PHP_FLOAT_EPSILON,
            default => false,
        };

        if ($breach) {
            $db = $this->db();
            $db->prepare(
                'INSERT INTO alert_events (rule_id, metric_value) VALUES (:rid, :val)'
            )->execute([':rid' => (int)$rule['id'], ':val' => $value]);
            $db->prepare(
                'UPDATE alert_rules SET last_triggered_at = CURRENT_TIMESTAMP WHERE id = :id'
            )->execute([':id' => (int)$rule['id']]);
        }

        return $breach;
    }

    /**
     * Find a rule by name.
     *
     * @return array<string,mixed>|null
     */
    public function find(string $name): ?array
    {
        $name = $this->validateName($name);
        $stmt = $this->db()->prepare(
            'SELECT id, name, metric, threshold, condition_op, enabled, last_triggered_at, created_at
             FROM alert_rules WHERE name = :name LIMIT 1'
        );
        $stmt->execute([':name' => $name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * List all rules, optionally filtered by enabled state.
     *
     * @param bool|null $enabled null = all, true = enabled only, false = disabled only.
     * @return list<array<string,mixed>>
     */
    public function all(?bool $enabled = null): array
    {
        if ($enabled === null) {
            $stmt = $this->db()->prepare(
                'SELECT id, name, metric, threshold, condition_op, enabled, last_triggered_at, created_at
                 FROM alert_rules ORDER BY name ASC'
            );
            $stmt->execute();
        } else {
            $stmt = $this->db()->prepare(
                'SELECT id, name, metric, threshold, condition_op, enabled, last_triggered_at, created_at
                 FROM alert_rules WHERE enabled = :en ORDER BY name ASC'
            );
            $stmt->execute([':en' => $enabled ? 1 : 0]);
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Get the alert event history for a rule.
     *
     * @return list<array{id: int, rule_id: int, metric_value: float, triggered_at: string}>
     */
    public function history(string $name, int $limit = 20): array
    {
        $name  = $this->validateName($name);
        $limit = max(1, $limit);
        $stmt  = $this->db()->prepare(
            "SELECT e.id, e.rule_id, e.metric_value, e.triggered_at
             FROM alert_events e
             JOIN alert_rules r ON r.id = e.rule_id
             WHERE r.name = :name
             ORDER BY e.id DESC
             LIMIT {$limit}"
        );
        $stmt->execute([':name' => $name]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Delete a rule and all its events.
     *
     * @return bool True if the rule existed.
     */
    public function delete(string $name): bool
    {
        $name = $this->validateName($name);
        $db   = $this->db();
        // Delete events first
        $stmt = $db->prepare('SELECT id FROM alert_rules WHERE name = :name LIMIT 1');
        $stmt->execute([':name' => $name]);
        $ruleId = $stmt->fetchColumn();
        if ($ruleId !== false) {
            $db->prepare('DELETE FROM alert_events WHERE rule_id = :rid')->execute([':rid' => (int)$ruleId]);
        }
        $del = $db->prepare('DELETE FROM alert_rules WHERE name = :name');
        $del->execute([':name' => $name]);
        return $del->rowCount() > 0;
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    private function validateName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('rule name must not be empty.');
        }
        return $name;
    }

    private function setEnabled(string $name, bool $enabled): bool
    {
        $name = $this->validateName($name);
        $stmt = $this->db()->prepare(
            'UPDATE alert_rules SET enabled = :en WHERE name = :name'
        );
        $stmt->execute([':en' => $enabled ? 1 : 0, ':name' => $name]);
        return $stmt->rowCount() > 0;
    }
}
