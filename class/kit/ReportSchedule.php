<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\DbUpsert;
use Nene\Xion\PdoConnection;
use PDO;

/**
 * ReportSchedule — recurring report definitions with a next-run clock.
 *
 * Stores named scheduled reports — their recipients, output format, and
 * fixed-interval cadence — and tracks when each is next due. A report cron
 * calls {@see ReportSchedule::due()} to find reports to generate, then
 * {@see ReportSchedule::markGenerated()} to advance the next-run time by the
 * interval (cadence-preserving, no drift). Distinct from `ScheduledTask`
 * (FT174, a generic last-run registry): this carries report-specific config
 * (recipients, format) and an interval-advancing next-run.
 *
 * ## Usage
 *
 * ```php
 * $rs = new ReportSchedule($pdo);
 *
 * $rs->schedule('weekly-sales', intervalDays: 7, recipients: ['ops@x.com'], format: 'pdf', firstRun: '2026-06-01 06:00:00');
 *
 * foreach ($rs->due('2026-06-01 07:00:00') as $r) {
 *     // generate $r['name'] for $r['recipients'] in $r['format'] …
 *     $rs->markGenerated($r['name']); // next_run += 7 days
 * }
 *
 * $rs->pause('weekly-sales');   // stop without deleting
 * $rs->resume('weekly-sales');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE report_schedules (
 *     id            INTEGER PRIMARY KEY AUTOINCREMENT,
 *     name          VARCHAR(150) NOT NULL,
 *     recipients    TEXT         NOT NULL DEFAULT '[]',
 *     format        VARCHAR(20)  NOT NULL DEFAULT 'csv',
 *     interval_days INTEGER      NOT NULL,
 *     next_run      DATETIME     NOT NULL,
 *     active        INTEGER      NOT NULL DEFAULT 1,
 *     created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (name)
 * );
 * ```
 */
final class ReportSchedule
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Create or replace a scheduled report. Idempotent per name; re-scheduling
     * resets cadence and re-activates.
     *
     * @param  string             $name        Report name.
     * @param  int                $intervalDays Cadence in days (>= 1).
     * @param  array<int,string>  $recipients  Recipient list.
     * @param  string             $format      Output format (e.g. 'csv', 'pdf').
     * @param  string|null        $firstRun    First run time; defaults to now.
     * @throws \InvalidArgumentException on empty name, interval < 1, or bad time.
     */
    public function schedule(string $name, int $intervalDays, array $recipients = [], string $format = 'csv', ?string $firstRun = null): void
    {
        $name = $this->validate($name);
        if ($intervalDays < 1) {
            throw new \InvalidArgumentException('Interval must be at least 1 day.');
        }

        DbUpsert::run(
            $this->db(),
            table:        'report_schedules',
            data:         [
                'name'          => $name,
                'recipients'    => json_encode(array_values($recipients), JSON_THROW_ON_ERROR),
                'format'        => trim($format),
                'interval_days' => $intervalDays,
                'next_run'      => $this->ts($firstRun),
                'active'        => 1,
            ],
            conflictCols: ['name'],
            updateCols:   ['recipients', 'format', 'interval_days', 'next_run', 'active'],
        );
    }

    /**
     * Active reports due to run (next_run on or before the reference time),
     * soonest first.
     *
     * @param  string|null $asOf Reference time; defaults to now.
     * @return array<int,array{name:string,recipients:array<int,string>,format:string,next_run:string}>
     */
    public function due(?string $asOf = null): array
    {
        $stmt = $this->db()->prepare(
            'SELECT name, recipients, format, next_run FROM report_schedules
             WHERE active = 1 AND next_run <= ? ORDER BY next_run ASC'
        );
        $stmt->execute([$this->ts($asOf)]);

        $out = [];
        /** @var array<string,mixed> $row */
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = [
                'name'       => (string)$row['name'],
                'recipients' => $this->decodeRecipients((string)$row['recipients']),
                'format'     => (string)$row['format'],
                'next_run'   => (string)$row['next_run'],
            ];
        }

        return $out;
    }

    /**
     * Advance a report's next-run time by its interval (cadence-preserving).
     *
     * @param  string $name Report name (must be scheduled).
     * @throws \InvalidArgumentException if the report is not scheduled.
     */
    public function markGenerated(string $name): void
    {
        $name = $this->validate($name);
        $row  = $this->fetch($name);
        if ($row === null) {
            throw new \InvalidArgumentException("Report is not scheduled: {$name}");
        }

        $next = (new \DateTimeImmutable((string)$row['next_run']))
            ->add(new \DateInterval('P' . (int)$row['interval_days'] . 'D'))
            ->format('Y-m-d H:i:s');

        $stmt = $this->db()->prepare('UPDATE report_schedules SET next_run = ? WHERE name = ?');
        $stmt->execute([$next, $name]);
    }

    /**
     * Return a report's full definition, or null.
     *
     * @return array{name:string,recipients:array<int,string>,format:string,interval_days:int,next_run:string,active:bool}|null
     */
    public function get(string $name): ?array
    {
        $row = $this->fetch($this->validate($name));
        if ($row === null) {
            return null;
        }

        return [
            'name'          => (string)$row['name'],
            'recipients'    => $this->decodeRecipients((string)$row['recipients']),
            'format'        => (string)$row['format'],
            'interval_days' => (int)$row['interval_days'],
            'next_run'      => (string)$row['next_run'],
            'active'        => (bool)$row['active'],
        ];
    }

    /**
     * Pause a report (excluded from due()). No-op if absent.
     */
    public function pause(string $name): void
    {
        $this->setActive($name, false);
    }

    /**
     * Resume a paused report. No-op if absent.
     */
    public function resume(string $name): void
    {
        $this->setActive($name, true);
    }

    /**
     * Delete a scheduled report. No-op if absent.
     */
    public function remove(string $name): void
    {
        $name = $this->validate($name);
        $stmt = $this->db()->prepare('DELETE FROM report_schedules WHERE name = ?');
        $stmt->execute([$name]);
    }

    // ── private ───────────────────────────────────────────────────────────────

    private function setActive(string $name, bool $active): void
    {
        $name = $this->validate($name);
        $stmt = $this->db()->prepare('UPDATE report_schedules SET active = ? WHERE name = ?');
        $stmt->execute([$active ? 1 : 0, $name]);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function fetch(string $name): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT name, recipients, format, interval_days, next_run, active FROM report_schedules WHERE name = ?'
        );
        $stmt->execute([$name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * @return array<int,string>
     */
    private function decodeRecipients(string $json): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_map(static fn ($r): string => (string)$r, array_values($decoded));
    }

    private function validate(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Name must not be empty.');
        }

        return $name;
    }

    private function ts(?string $value): string
    {
        $epoch = strtotime($value ?? 'now');
        if ($epoch === false) {
            throw new \InvalidArgumentException('Invalid time.');
        }

        return date('Y-m-d H:i:s', $epoch);
    }

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
