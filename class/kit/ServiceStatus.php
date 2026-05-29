<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\DbUpsert;
use Nene\Xion\PdoConnection;
use PDO;

/**
 * ServiceStatus — public status-page component states with an overall roll-up.
 *
 * Holds the current operational state of each named component (API, web,
 * database, …) for a public status page, and rolls them up to a single
 * overall status (the worst component). Distinct from `HealthCheck` (FT225,
 * internal probe log), `Heartbeat` (FT273, liveness), and `IncidentLog`
 * (incident tracking): this is the operator-set, user-facing status board.
 *
 * Severity order (low → high): operational, maintenance, degraded,
 * partial_outage, major_outage. `overall()` returns the highest present (or
 * `operational` when nothing is registered).
 *
 * ## Usage
 *
 * ```php
 * $ss = new ServiceStatus($pdo);
 *
 * $ss->setStatus('api', ServiceStatus::DEGRADED, 'Elevated latency');
 * $ss->setStatus('web', ServiceStatus::OPERATIONAL);
 *
 * $ss->statusOf('api');   // 'degraded'
 * $ss->overall();         // 'degraded' (worst component)
 * $ss->isOperational();   // false
 * $ss->components();       // [['component'=>'api','status'=>'degraded','message'=>...], ...]
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE service_status (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     component  VARCHAR(150) NOT NULL,
 *     status     VARCHAR(20)  NOT NULL,
 *     message    VARCHAR(255) NOT NULL DEFAULT '',
 *     updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (component)
 * );
 * ```
 */
final class ServiceStatus
{
    public const string OPERATIONAL     = 'operational';
    public const string MAINTENANCE     = 'maintenance';
    public const string DEGRADED        = 'degraded';
    public const string PARTIAL_OUTAGE  = 'partial_outage';
    public const string MAJOR_OUTAGE    = 'major_outage';

    /** Severity ranking (low → high). */
    private const array SEVERITY = [
        self::OPERATIONAL    => 0,
        self::MAINTENANCE    => 1,
        self::DEGRADED       => 2,
        self::PARTIAL_OUTAGE => 3,
        self::MAJOR_OUTAGE   => 4,
    ];

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Set a component's current status. Idempotent per component.
     *
     * @param  string $component Component name.
     * @param  string $status    One of the status constants.
     * @param  string $message   Optional human message.
     * @throws \InvalidArgumentException on empty component or unknown status.
     */
    public function setStatus(string $component, string $status, string $message = ''): void
    {
        $component = $this->validate($component);
        if (!isset(self::SEVERITY[$status])) {
            throw new \InvalidArgumentException("Unknown status: {$status}");
        }

        DbUpsert::run(
            $this->db(),
            table:        'service_status',
            data:         ['component' => $component, 'status' => $status, 'message' => $message],
            conflictCols: ['component'],
            updateCols:   ['status', 'message'],
            updateExprs:  ['updated_at' => 'CURRENT_TIMESTAMP'],
        );
    }

    /**
     * Current status of a component, or null if not registered.
     */
    public function statusOf(string $component): ?string
    {
        $component = $this->validate($component);
        $stmt      = $this->db()->prepare('SELECT status FROM service_status WHERE component = ?');
        $stmt->execute([$component]);
        $s = $stmt->fetchColumn();

        return $s === false ? null : (string)$s;
    }

    /**
     * All components and their statuses, by component name.
     *
     * @return array<int,array{component:string,status:string,message:string}>
     */
    public function components(): array
    {
        $stmt = $this->db()->query('SELECT component, status, message FROM service_status ORDER BY component ASC');
        $rows = $stmt === false ? [] : $stmt->fetchAll(PDO::FETCH_ASSOC);

        $out = [];
        /** @var array<string,mixed> $row */
        foreach ($rows as $row) {
            $out[] = [
                'component' => (string)$row['component'],
                'status'    => (string)$row['status'],
                'message'   => (string)$row['message'],
            ];
        }

        return $out;
    }

    /**
     * Overall status: the worst (highest-severity) component, or `operational`
     * when no components are registered.
     */
    public function overall(): string
    {
        $stmt = $this->db()->query('SELECT status FROM service_status');
        $rows = $stmt === false ? [] : $stmt->fetchAll(PDO::FETCH_COLUMN);

        $worst    = self::OPERATIONAL;
        $worstSev = 0;
        foreach ($rows as $status) {
            $sev = self::SEVERITY[(string)$status] ?? 0;
            if ($sev > $worstSev) {
                $worstSev = $sev;
                $worst    = (string)$status;
            }
        }

        return $worst;
    }

    /**
     * Whether everything is operational (overall == operational).
     */
    public function isOperational(): bool
    {
        return $this->overall() === self::OPERATIONAL;
    }

    /**
     * Remove a component. No-op if absent.
     */
    public function remove(string $component): void
    {
        $component = $this->validate($component);
        $stmt      = $this->db()->prepare('DELETE FROM service_status WHERE component = ?');
        $stmt->execute([$component]);
    }

    // ── private ───────────────────────────────────────────────────────────────

    private function validate(string $component): string
    {
        $component = trim($component);
        if ($component === '') {
            throw new \InvalidArgumentException('Component must not be empty.');
        }

        return $component;
    }

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
