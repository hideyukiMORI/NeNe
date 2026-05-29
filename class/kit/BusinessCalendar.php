<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\DbUpsert;
use Nene\Xion\PdoConnection;
use PDO;

/**
 * BusinessCalendar — working-day calendar with weekend and holiday awareness.
 *
 * Computes business days (excluding weekends and registered holidays) for
 * SLA deadlines, due-date arithmetic, and "deliver in N working days" style
 * scheduling. Several independent calendars can coexist, keyed by `calKey`
 * (e.g. one per country or per office), so a single deployment can serve
 * regions with different public holidays.
 *
 * "Business day" = Monday–Friday that is not a registered holiday. Saturday
 * and Sunday are always non-business days; there is currently no support for
 * regions with a non-Sat/Sun weekend (extend if a real need appears).
 *
 * ## Usage
 *
 * ```php
 * $cal = new BusinessCalendar($pdo);
 *
 * // Register public holidays for a calendar
 * $cal->addHoliday('jp', '2026-01-01', '元日');
 * $cal->addHoliday('jp', '2026-01-12', '成人の日');
 *
 * // Is a given date a working day?
 * $cal->isBusinessDay('jp', '2026-01-01'); // false (holiday)
 * $cal->isBusinessDay('jp', '2026-01-03'); // false (Saturday)
 * $cal->isBusinessDay('jp', '2026-01-05'); // true  (Monday)
 *
 * // Move forward/back by working days (skips weekends + holidays)
 * $cal->addBusinessDays('jp', '2026-01-05', 5);   // '2026-01-13'
 * $cal->addBusinessDays('jp', '2026-01-13', -2);  // '2026-01-08'
 * $cal->nextBusinessDay('jp', '2026-01-01');      // '2026-01-02' (Fri, not a holiday)
 *
 * // Count working days in a half-open range [start, end)
 * $cal->businessDaysBetween('jp', '2026-01-05', '2026-01-13'); // 5
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE calendar_holidays (
 *     id           INTEGER PRIMARY KEY AUTOINCREMENT,
 *     cal_key      VARCHAR(50)  NOT NULL,
 *     holiday_date CHAR(10)     NOT NULL,
 *     label        VARCHAR(255) NOT NULL DEFAULT '',
 *     created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (cal_key, holiday_date)
 * );
 * ```
 */
final class BusinessCalendar
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── holidays ────────────────────────────────────────────────────────────────

    /**
     * Register (or relabel) a holiday for a calendar. Idempotent per date.
     *
     * @param  string $calKey Calendar identifier (e.g. 'jp').
     * @param  string $date   Holiday date as 'Y-m-d'.
     * @param  string $label  Optional human label.
     * @throws \InvalidArgumentException if $calKey is empty or $date is malformed.
     */
    public function addHoliday(string $calKey, string $date, string $label = ''): void
    {
        $calKey = $this->validateKey($calKey);
        $date   = $this->normalizeDate($date);

        DbUpsert::run(
            $this->db(),
            table:        'calendar_holidays',
            data:         ['cal_key' => $calKey, 'holiday_date' => $date, 'label' => $label],
            conflictCols: ['cal_key', 'holiday_date'],
            updateCols:   ['label'],
        );
    }

    /**
     * Remove a holiday. No-op if it was not registered.
     *
     * @param string $calKey Calendar identifier.
     * @param string $date   Holiday date as 'Y-m-d'.
     */
    public function removeHoliday(string $calKey, string $date): void
    {
        $calKey = $this->validateKey($calKey);
        $date   = $this->normalizeDate($date);

        $stmt = $this->db()->prepare(
            'DELETE FROM calendar_holidays WHERE cal_key = ? AND holiday_date = ?'
        );
        $stmt->execute([$calKey, $date]);
    }

    /**
     * Return whether a date is a registered holiday (ignores weekends).
     *
     * @param  string $calKey Calendar identifier.
     * @param  string $date   Date as 'Y-m-d'.
     * @return bool
     */
    public function isHoliday(string $calKey, string $date): bool
    {
        $calKey = $this->validateKey($calKey);
        $date   = $this->normalizeDate($date);

        $stmt = $this->db()->prepare(
            'SELECT 1 FROM calendar_holidays WHERE cal_key = ? AND holiday_date = ? LIMIT 1'
        );
        $stmt->execute([$calKey, $date]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * List registered holidays for a calendar within a half-open range [from, to).
     *
     * @param  string      $calKey Calendar identifier.
     * @param  string|null $from   Inclusive lower bound 'Y-m-d', or null for no lower bound.
     * @param  string|null $to     Exclusive upper bound 'Y-m-d', or null for no upper bound.
     * @return array<int,array{date:string,label:string}> Ordered by date ascending.
     */
    public function holidays(string $calKey, ?string $from = null, ?string $to = null): array
    {
        $calKey = $this->validateKey($calKey);

        $sql    = 'SELECT holiday_date, label FROM calendar_holidays WHERE cal_key = ?';
        $params = [$calKey];

        if ($from !== null) {
            $sql     .= ' AND holiday_date >= ?';
            $params[] = $this->normalizeDate($from);
        }
        if ($to !== null) {
            $sql     .= ' AND holiday_date < ?';
            $params[] = $this->normalizeDate($to);
        }
        $sql .= ' ORDER BY holiday_date ASC';

        $stmt = $this->db()->prepare($sql);
        $stmt->execute($params);

        $out = [];
        /** @var array<string,mixed> $row */
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = ['date' => (string)$row['holiday_date'], 'label' => (string)$row['label']];
        }

        return $out;
    }

    // ── business-day queries ──────────────────────────────────────────────────

    /**
     * Return whether a date is a business day (Mon–Fri and not a holiday).
     *
     * @param string $calKey Calendar identifier.
     * @param string $date   Date as 'Y-m-d'.
     */
    public function isBusinessDay(string $calKey, string $date): bool
    {
        $calKey = $this->validateKey($calKey);
        $date   = $this->normalizeDate($date);

        if ($this->isWeekend($date)) {
            return false;
        }

        return !$this->isHoliday($calKey, $date);
    }

    /**
     * Return the next business day strictly after the given date.
     *
     * @param  string $calKey Calendar identifier.
     * @param  string $date   Date as 'Y-m-d'.
     * @return string         Date as 'Y-m-d'.
     */
    public function nextBusinessDay(string $calKey, string $date): string
    {
        return $this->addBusinessDays($calKey, $date, 1);
    }

    /**
     * Return the previous business day strictly before the given date.
     *
     * @param  string $calKey Calendar identifier.
     * @param  string $date   Date as 'Y-m-d'.
     * @return string         Date as 'Y-m-d'.
     */
    public function previousBusinessDay(string $calKey, string $date): string
    {
        return $this->addBusinessDays($calKey, $date, -1);
    }

    /**
     * Add (or subtract, if negative) a number of business days to a date.
     *
     * Moves day-by-day, skipping weekends and holidays. With $days === 0 the
     * input date is returned unchanged even if it is not itself a business day.
     *
     * @param  string $calKey Calendar identifier.
     * @param  string $date   Start date as 'Y-m-d'.
     * @param  int    $days   Number of business days to move (may be negative).
     * @return string         Resulting date as 'Y-m-d'.
     */
    public function addBusinessDays(string $calKey, string $date, int $days): string
    {
        $calKey  = $this->validateKey($calKey);
        $current = $this->toDate($this->normalizeDate($date));

        if ($days === 0) {
            return $current->format('Y-m-d');
        }

        $step      = $days > 0 ? 1 : -1;
        $remaining = abs($days);
        $interval  = new \DateInterval('P1D');

        while ($remaining > 0) {
            $current = $step > 0 ? $current->add($interval) : $current->sub($interval);
            if ($this->isBusinessDay($calKey, $current->format('Y-m-d'))) {
                $remaining--;
            }
        }

        return $current->format('Y-m-d');
    }

    /**
     * Count business days in the half-open range [from, to).
     *
     * Returns 0 if $from >= $to. The end date is exclusive, so
     * businessDaysBetween('jp', 'Mon', 'Sat') over a holiday-free week is 5.
     *
     * @param  string $calKey Calendar identifier.
     * @param  string $from   Inclusive start date as 'Y-m-d'.
     * @param  string $to     Exclusive end date as 'Y-m-d'.
     * @return int
     */
    public function businessDaysBetween(string $calKey, string $from, string $to): int
    {
        $calKey = $this->validateKey($calKey);
        $start  = $this->toDate($this->normalizeDate($from));
        $end    = $this->toDate($this->normalizeDate($to));

        if ($start >= $end) {
            return 0;
        }

        $count    = 0;
        $cursor   = $start;
        $interval = new \DateInterval('P1D');

        while ($cursor < $end) {
            if ($this->isBusinessDay($calKey, $cursor->format('Y-m-d'))) {
                $count++;
            }
            $cursor = $cursor->add($interval);
        }

        return $count;
    }

    // ── private ───────────────────────────────────────────────────────────────

    private function isWeekend(string $date): bool
    {
        // 6 = Saturday, 7 = Sunday (ISO-8601 day-of-week).
        $dow = (int)$this->toDate($date)->format('N');

        return $dow >= 6;
    }

    private function validateKey(string $calKey): string
    {
        $calKey = trim($calKey);
        if ($calKey === '') {
            throw new \InvalidArgumentException('Calendar key must not be empty.');
        }

        return $calKey;
    }

    private function normalizeDate(string $date): string
    {
        return $this->toDate($date)->format('Y-m-d');
    }

    private function toDate(string $date): \DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        // Round-trip guard: rejects malformed input ('2026-13-40') and silent
        // overflow ('2026-02-30' → Mar 2) by requiring the parse to re-render
        // identically. Requires zero-padded 'Y-m-d', which is the API contract.
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException("Invalid date (expected Y-m-d): {$date}");
        }

        return $parsed;
    }

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
