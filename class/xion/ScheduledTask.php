<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * ScheduledTask — cron-style task schedule registry with last-run tracking.
 *
 * Stores named recurring tasks with their schedule interval (in seconds) and
 * the timestamp of their last successful run. A task is "due" when the
 * elapsed time since last_run_at exceeds the interval. The runner calls
 * `due()` to find which tasks to execute, then `markRan()` after each one.
 *
 * This complements CronLog (which records individual execution outcomes)
 * by providing the schedule definition layer.
 *
 * ## Usage
 *
 * ```php
 * $st = new ScheduledTask($pdo);
 *
 * // Register tasks (idempotent)
 * $st->register('send_digests', 3600);          // every hour
 * $st->register('cleanup_sessions', 86400);     // daily
 *
 * // In a cron runner (called every minute):
 * foreach ($st->due() as $task) {
 *     run($task['name']);
 *     $st->markRan($task['name']);
 * }
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE scheduled_tasks (
 *     id            INTEGER PRIMARY KEY AUTOINCREMENT,
 *     name          VARCHAR(100) NOT NULL UNIQUE,
 *     interval_sec  INTEGER      NOT NULL,
 *     enabled       TINYINT(1)   NOT NULL DEFAULT 1,
 *     last_run_at   DATETIME     NULL,
 *     registered_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class ScheduledTask
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Register a task (idempotent — updates interval if already registered).
     *
     * @throws \InvalidArgumentException on empty name or non-positive interval.
     */
    public function register(string $name, int $intervalSec): void
    {
        $name = $this->validateName($name);
        if ($intervalSec <= 0) {
            throw new \InvalidArgumentException('interval_sec must be positive.');
        }
        $db = $this->db();

        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $db->prepare(
                'INSERT INTO scheduled_tasks (name, interval_sec)
                 VALUES (:name, :interval)
                 ON CONFLICT (name)
                 DO UPDATE SET interval_sec = excluded.interval_sec'
            )->execute([':name' => $name, ':interval' => $intervalSec]);
        } else {
            $db->prepare(
                'INSERT INTO scheduled_tasks (name, interval_sec)
                 VALUES (:name, :interval)
                 ON DUPLICATE KEY UPDATE interval_sec = VALUES(interval_sec)'
            )->execute([':name' => $name, ':interval' => $intervalSec]);
        }
    }

    /**
     * Return all tasks that are currently due.
     *
     * A task is due if: it is enabled AND (last_run_at IS NULL OR
     * last_run_at + interval_sec <= NOW).
     *
     * @return list<array<string,mixed>>
     */
    public function due(): array
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            "SELECT id, name, interval_sec, enabled, last_run_at, registered_at
             FROM scheduled_tasks
             WHERE enabled = 1
               AND (last_run_at IS NULL
                    OR datetime(last_run_at, '+' || interval_sec || ' seconds') <= :now)
             ORDER BY name ASC"
        );
        $stmt->execute([':now' => $now]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Mark a task as ran (updates last_run_at to NOW).
     *
     * @return bool True if the task was found and updated.
     */
    public function markRan(string $name): bool
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'UPDATE scheduled_tasks SET last_run_at = :now WHERE name = :name'
        );
        $stmt->execute([':now' => $now, ':name' => $this->validateName($name)]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Enable a task (re-enables after disable).
     *
     * @return bool True if found and updated.
     */
    public function enable(string $name): bool
    {
        return $this->setEnabled($name, true);
    }

    /**
     * Disable a task (it won't appear in due() until re-enabled).
     *
     * @return bool True if found and updated.
     */
    public function disable(string $name): bool
    {
        return $this->setEnabled($name, false);
    }

    /**
     * Find a task by name.
     *
     * @return array<string,mixed>|null
     */
    public function find(string $name): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, name, interval_sec, enabled, last_run_at, registered_at
             FROM scheduled_tasks WHERE name = :name LIMIT 1'
        );
        $stmt->execute([':name' => trim($name)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * List all registered tasks.
     *
     * @return list<array<string,mixed>>
     */
    public function all(): array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, name, interval_sec, enabled, last_run_at, registered_at
             FROM scheduled_tasks ORDER BY name ASC'
        );
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Remove a task from the schedule.
     *
     * @return bool True if found and deleted.
     */
    public function unregister(string $name): bool
    {
        $stmt = $this->db()->prepare('DELETE FROM scheduled_tasks WHERE name = :name');
        $stmt->execute([':name' => $this->validateName($name)]);
        return $stmt->rowCount() > 0;
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
            throw new \InvalidArgumentException('name must not be empty.');
        }
        return $name;
    }

    private function setEnabled(string $name, bool $enabled): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE scheduled_tasks SET enabled = :enabled WHERE name = :name'
        );
        $stmt->execute([':enabled' => $enabled ? 1 : 0, ':name' => $this->validateName($name)]);
        return $stmt->rowCount() > 0;
    }
}
