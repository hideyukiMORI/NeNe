<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * MaintenanceWindow — scheduled maintenance windows per service scope.
 *
 * Records planned maintenance intervals so an app can show a banner, return
 * 503 during the window, or gate background jobs. Distinct from
 * `MaintenanceMode` (FT87), which is a single global on/off flag: this models
 * *time-bounded, scheduled* windows, can hold several upcoming entries, and is
 * scoped (e.g. one per service or region).
 *
 * A window is "active" during the half-open interval `[starts_at, ends_at)`.
 *
 * ## Usage
 *
 * ```php
 * $mw = new MaintenanceWindow($pdo);
 *
 * $id = $mw->schedule('api', '2026-06-01 02:00:00', '2026-06-01 04:00:00', 'DB upgrade');
 *
 * $mw->isActive('api', '2026-06-01 03:00:00');   // true
 * $mw->isActive('api', '2026-06-01 04:00:00');   // false (end exclusive)
 * $mw->activeWindow('api', '2026-06-01 03:00:00'); // ['id'=>.., 'reason'=>'DB upgrade', ...]
 *
 * $mw->upcoming('api', '2026-05-30 00:00:00');   // windows starting after the ref time
 *
 * $mw->cancel($id);
 * $mw->purgeEnded('2026-07-01 00:00:00');        // drop windows that ended before a date
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE maintenance_windows (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     scope      VARCHAR(100) NOT NULL,
 *     starts_at  DATETIME     NOT NULL,
 *     ends_at    DATETIME     NOT NULL,
 *     reason     VARCHAR(255) NOT NULL DEFAULT '',
 *     created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class MaintenanceWindow
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Schedule a maintenance window.
     *
     * @param  string $scope    Service scope (e.g. 'api').
     * @param  string $startsAt Start timestamp ('Y-m-d H:i:s' or parseable).
     * @param  string $endsAt   End timestamp; must be strictly after $startsAt.
     * @param  string $reason   Optional human reason.
     * @return int              New window id.
     * @throws \InvalidArgumentException on empty scope, bad timestamps, or end <= start.
     */
    public function schedule(string $scope, string $startsAt, string $endsAt, string $reason = ''): int
    {
        $scope = $this->validateScope($scope);
        $start = $this->parse($startsAt);
        $end   = $this->parse($endsAt);
        if ($end <= $start) {
            throw new \InvalidArgumentException('End must be strictly after start.');
        }

        $stmt = $this->db()->prepare(
            'INSERT INTO maintenance_windows (scope, starts_at, ends_at, reason)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$scope, $start, $end, $reason]);

        return (int)$this->db()->lastInsertId();
    }

    /**
     * Whether a maintenance window is active at a point in time.
     *
     * @param string      $scope Service scope.
     * @param string|null $asOf  Reference timestamp; defaults to now.
     */
    public function isActive(string $scope, ?string $asOf = null): bool
    {
        return $this->activeWindow($scope, $asOf) !== null;
    }

    /**
     * Return the active window at a point in time, or null.
     *
     * If windows overlap, the one ending soonest is returned.
     *
     * @param  string      $scope Service scope.
     * @param  string|null $asOf  Reference timestamp; defaults to now.
     * @return array{id:int,scope:string,starts_at:string,ends_at:string,reason:string}|null
     */
    public function activeWindow(string $scope, ?string $asOf = null): ?array
    {
        $scope = $this->validateScope($scope);
        $now   = $this->parse($asOf ?? 'now');

        $stmt = $this->db()->prepare(
            'SELECT id, scope, starts_at, ends_at, reason FROM maintenance_windows
             WHERE scope = ? AND starts_at <= ? AND ends_at > ?
             ORDER BY ends_at ASC LIMIT 1'
        );
        $stmt->execute([$scope, $now, $now]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * List windows starting strictly after a reference time, soonest first.
     *
     * @param  string      $scope Service scope.
     * @param  string|null $asOf  Reference timestamp; defaults to now.
     * @return array<int,array{id:int,scope:string,starts_at:string,ends_at:string,reason:string}>
     */
    public function upcoming(string $scope, ?string $asOf = null): array
    {
        $scope = $this->validateScope($scope);
        $now   = $this->parse($asOf ?? 'now');

        $stmt = $this->db()->prepare(
            'SELECT id, scope, starts_at, ends_at, reason FROM maintenance_windows
             WHERE scope = ? AND starts_at > ?
             ORDER BY starts_at ASC'
        );
        $stmt->execute([$scope, $now]);

        $out = [];
        /** @var array<string,mixed> $row */
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = $this->hydrate($row);
        }

        return $out;
    }

    /**
     * Cancel (delete) a scheduled window by id. No-op if absent.
     */
    public function cancel(int $id): void
    {
        $stmt = $this->db()->prepare('DELETE FROM maintenance_windows WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * Delete windows that ended on or before a reference time.
     *
     * @param  string|null $asOf Reference timestamp; defaults to now.
     * @return int               Number of windows removed.
     */
    public function purgeEnded(?string $asOf = null): int
    {
        $now  = $this->parse($asOf ?? 'now');
        $stmt = $this->db()->prepare('DELETE FROM maintenance_windows WHERE ends_at <= ?');
        $stmt->execute([$now]);

        return $stmt->rowCount();
    }

    // ── private ───────────────────────────────────────────────────────────────

    /**
     * @param  array<string,mixed> $row
     * @return array{id:int,scope:string,starts_at:string,ends_at:string,reason:string}
     */
    private function hydrate(array $row): array
    {
        return [
            'id'        => (int)$row['id'],
            'scope'     => (string)$row['scope'],
            'starts_at' => (string)$row['starts_at'],
            'ends_at'   => (string)$row['ends_at'],
            'reason'    => (string)$row['reason'],
        ];
    }

    private function validateScope(string $scope): string
    {
        $scope = trim($scope);
        if ($scope === '') {
            throw new \InvalidArgumentException('Scope must not be empty.');
        }

        return $scope;
    }

    private function parse(string $value): string
    {
        $ts = strtotime($value);
        if ($ts === false) {
            throw new \InvalidArgumentException("Invalid timestamp: {$value}");
        }

        return date('Y-m-d H:i:s', $ts);
    }

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
