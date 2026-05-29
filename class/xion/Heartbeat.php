<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * Heartbeat — liveness / dead-man-switch tracking per service.
 *
 * Each worker, cron, or service records a periodic `beat()`; monitoring then
 * asks whether a service is still alive (beat within a freshness window) or
 * has gone `stale()`. Distinct from `HealthCheck` (FT225), which logs rich
 * check results (status, latency) over time — this is a single
 * last-seen-timestamp per service, optimised for "is X still running?".
 *
 * Timestamps are stored as `'Y-m-d H:i:s'` (lexicographically comparable). An
 * `$asOf` parameter on the time-sensitive methods keeps tests deterministic.
 *
 * ## Usage
 *
 * ```php
 * $hb = new Heartbeat($pdo);
 *
 * $hb->beat('cron.cleanup');               // record a beat now
 * $hb->lastBeat('cron.cleanup');           // '2026-05-29 12:00:00'
 *
 * $hb->isAlive('cron.cleanup', 300);       // beat within last 5 min?
 * $hb->stale(300);                         // services with no recent beat
 * $hb->alive(300);                         // services beating recently
 *
 * $hb->forget('cron.cleanup');             // deregister
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE heartbeats (
 *     id        INTEGER PRIMARY KEY AUTOINCREMENT,
 *     service   VARCHAR(150) NOT NULL,
 *     last_beat DATETIME     NOT NULL,
 *     UNIQUE (service)
 * );
 * ```
 */
final class Heartbeat
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Record a heartbeat for a service (upsert of its last-seen timestamp).
     *
     * @param  string      $service Service identifier.
     * @param  string|null $asOf    Beat time; defaults to now.
     * @throws \InvalidArgumentException on empty service or bad timestamp.
     */
    public function beat(string $service, ?string $asOf = null): void
    {
        $service = $this->validateService($service);
        $now     = $this->parse($asOf ?? 'now');

        DbUpsert::run(
            $this->db(),
            table:        'heartbeats',
            data:         ['service' => $service, 'last_beat' => $now],
            conflictCols: ['service'],
            updateCols:   ['last_beat'],
        );
    }

    /**
     * Return a service's last beat timestamp, or null if never seen.
     */
    public function lastBeat(string $service): ?string
    {
        $service = $this->validateService($service);
        $stmt    = $this->db()->prepare('SELECT last_beat FROM heartbeats WHERE service = ?');
        $stmt->execute([$service]);
        $beat = $stmt->fetchColumn();

        return $beat === false ? null : (string)$beat;
    }

    /**
     * Whether a service has beaten within the freshness window.
     *
     * A service never seen is not alive.
     *
     * @param string      $service       Service identifier.
     * @param int         $withinSeconds Freshness window (must be >= 1).
     * @param string|null $asOf          Reference time; defaults to now.
     */
    public function isAlive(string $service, int $withinSeconds, ?string $asOf = null): bool
    {
        $last = $this->lastBeat($service);
        if ($last === null) {
            return false;
        }

        return $last >= $this->cutoff($withinSeconds, $asOf);
    }

    /**
     * List services whose most recent beat is within the window, freshest first.
     *
     * @param  int         $withinSeconds Freshness window (>= 1).
     * @param  string|null $asOf          Reference time; defaults to now.
     * @return array<int,array{service:string,last_beat:string}>
     */
    public function alive(int $withinSeconds, ?string $asOf = null): array
    {
        return $this->query('last_beat >= ?', $this->cutoff($withinSeconds, $asOf), 'DESC');
    }

    /**
     * List services whose most recent beat is older than the window (or, by
     * definition, services that have stopped beating), oldest first.
     *
     * @param  int         $withinSeconds Freshness window (>= 1).
     * @param  string|null $asOf          Reference time; defaults to now.
     * @return array<int,array{service:string,last_beat:string}>
     */
    public function stale(int $withinSeconds, ?string $asOf = null): array
    {
        return $this->query('last_beat < ?', $this->cutoff($withinSeconds, $asOf), 'ASC');
    }

    /**
     * Remove a service's heartbeat record. No-op if absent.
     */
    public function forget(string $service): void
    {
        $service = $this->validateService($service);
        $stmt    = $this->db()->prepare('DELETE FROM heartbeats WHERE service = ?');
        $stmt->execute([$service]);
    }

    /**
     * List all known services and their last beat, freshest first.
     *
     * @return array<int,array{service:string,last_beat:string}>
     */
    public function all(): array
    {
        $stmt = $this->db()->query('SELECT service, last_beat FROM heartbeats ORDER BY last_beat DESC');
        $rows = $stmt === false ? [] : $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $this->hydrateRows($rows);
    }

    // ── private ───────────────────────────────────────────────────────────────

    /**
     * @return array<int,array{service:string,last_beat:string}>
     */
    private function query(string $where, string $cutoff, string $dir): array
    {
        $stmt = $this->db()->prepare(
            "SELECT service, last_beat FROM heartbeats WHERE {$where} ORDER BY last_beat {$dir}"
        );
        $stmt->execute([$cutoff]);

        return $this->hydrateRows($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param  array<int,array<string,mixed>> $rows
     * @return array<int,array{service:string,last_beat:string}>
     */
    private function hydrateRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = ['service' => (string)$row['service'], 'last_beat' => (string)$row['last_beat']];
        }

        return $out;
    }

    private function cutoff(int $withinSeconds, ?string $asOf): string
    {
        if ($withinSeconds < 1) {
            throw new \InvalidArgumentException('Window must be at least 1 second.');
        }
        $ref = strtotime($asOf ?? 'now');
        if ($ref === false) {
            throw new \InvalidArgumentException('Invalid reference time.');
        }

        return date('Y-m-d H:i:s', $ref - $withinSeconds);
    }

    private function parse(string $value): string
    {
        $ts = strtotime($value);
        if ($ts === false) {
            throw new \InvalidArgumentException("Invalid timestamp: {$value}");
        }

        return date('Y-m-d H:i:s', $ts);
    }

    private function validateService(string $service): string
    {
        $service = trim($service);
        if ($service === '') {
            throw new \InvalidArgumentException('Service must not be empty.');
        }

        return $service;
    }

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
