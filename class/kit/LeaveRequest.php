<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * LeaveRequest — employee time-off requests with an approval workflow.
 *
 * Tracks paid-leave / vacation / sick requests per employee: submit a request
 * for a date range and day count, then approve or reject it. Reports a
 * running total of approved days (overall or by leave type). The day count is
 * supplied by the caller (use `BusinessCalendar` (FT266) to compute working
 * days). Distinct from `Approval` (FT, a generic single-approver workflow):
 * this carries leave-specific fields and day accounting.
 *
 * ## Usage
 *
 * ```php
 * $lr = new LeaveRequest($pdo);
 *
 * $id = $lr->request('emp-7', 'vacation', '2026-07-01', '2026-07-05', days: 5);
 *
 * $lr->approve($id);
 * $lr->status($id);                  // 'approved'
 * $lr->approvedDays('emp-7');        // 5
 * $lr->pending();                    // approver inbox (all pending)
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE leave_requests (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     employee   VARCHAR(190) NOT NULL,
 *     type       VARCHAR(50)  NOT NULL,
 *     start_date CHAR(10)     NOT NULL,
 *     end_date   CHAR(10)     NOT NULL,
 *     days       INTEGER      NOT NULL,
 *     status     VARCHAR(10)  NOT NULL DEFAULT 'pending',
 *     created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class LeaveRequest
{
    public const string STATUS_PENDING  = 'pending';
    public const string STATUS_APPROVED = 'approved';
    public const string STATUS_REJECTED = 'rejected';

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Submit a pending leave request.
     *
     * @param  string $employee  Employee identifier.
     * @param  string $type      Leave type (e.g. 'vacation', 'sick').
     * @param  string $startDate Start date 'Y-m-d'.
     * @param  string $endDate   End date 'Y-m-d' (>= start).
     * @param  int    $days      Number of leave days (>= 1).
     * @return int               New request id.
     * @throws \InvalidArgumentException on empty fields, days < 1, or end < start.
     */
    public function request(string $employee, string $type, string $startDate, string $endDate, int $days): int
    {
        $employee = $this->validate($employee, 'Employee');
        $type     = $this->validate($type, 'Type');
        $start    = $this->date($startDate);
        $end      = $this->date($endDate);
        if ($end < $start) {
            throw new \InvalidArgumentException('End date must not be before start date.');
        }
        if ($days < 1) {
            throw new \InvalidArgumentException('Days must be at least 1.');
        }

        $stmt = $this->db()->prepare(
            'INSERT INTO leave_requests (employee, type, start_date, end_date, days, status)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$employee, $type, $start, $end, $days, self::STATUS_PENDING]);

        return (int)$this->db()->lastInsertId();
    }

    /**
     * Approve a pending request. Returns true if it was pending.
     */
    public function approve(int $id): bool
    {
        return $this->transition($id, self::STATUS_APPROVED);
    }

    /**
     * Reject a pending request. Returns true if it was pending.
     */
    public function reject(int $id): bool
    {
        return $this->transition($id, self::STATUS_REJECTED);
    }

    /**
     * Status of a request, or null if unknown.
     */
    public function status(int $id): ?string
    {
        $stmt = $this->db()->prepare('SELECT status FROM leave_requests WHERE id = ?');
        $stmt->execute([$id]);
        $s = $stmt->fetchColumn();

        return $s === false ? null : (string)$s;
    }

    /**
     * Total approved leave days for an employee, optionally by type.
     */
    public function approvedDays(string $employee, ?string $type = null): int
    {
        $employee = $this->validate($employee, 'Employee');

        if ($type === null) {
            $stmt = $this->db()->prepare(
                'SELECT COALESCE(SUM(days), 0) FROM leave_requests WHERE employee = ? AND status = ?'
            );
            $stmt->execute([$employee, self::STATUS_APPROVED]);
        } else {
            $stmt = $this->db()->prepare(
                'SELECT COALESCE(SUM(days), 0) FROM leave_requests WHERE employee = ? AND status = ? AND type = ?'
            );
            $stmt->execute([$employee, self::STATUS_APPROVED, $type]);
        }

        return (int)$stmt->fetchColumn();
    }

    /**
     * An employee's requests (optionally by status), newest first.
     *
     * @return array<int,array{id:int,type:string,start_date:string,end_date:string,days:int,status:string}>
     */
    public function requestsFor(string $employee, ?string $status = null): array
    {
        $employee = $this->validate($employee, 'Employee');

        if ($status === null) {
            $stmt = $this->db()->prepare(
                'SELECT id, type, start_date, end_date, days, status FROM leave_requests WHERE employee = ? ORDER BY id DESC'
            );
            $stmt->execute([$employee]);
        } else {
            $stmt = $this->db()->prepare(
                'SELECT id, type, start_date, end_date, days, status FROM leave_requests WHERE employee = ? AND status = ? ORDER BY id DESC'
            );
            $stmt->execute([$employee, $status]);
        }

        return $this->hydrateRows($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * All pending requests (approver inbox), oldest first.
     *
     * @return array<int,array{id:int,employee:string,type:string,start_date:string,end_date:string,days:int}>
     */
    public function pending(): array
    {
        $stmt = $this->db()->query(
            "SELECT id, employee, type, start_date, end_date, days FROM leave_requests WHERE status = 'pending' ORDER BY id ASC"
        );
        $rows = $stmt === false ? [] : $stmt->fetchAll(PDO::FETCH_ASSOC);

        $out = [];
        /** @var array<string,mixed> $r */
        foreach ($rows as $r) {
            $out[] = [
                'id'         => (int)$r['id'],
                'employee'   => (string)$r['employee'],
                'type'       => (string)$r['type'],
                'start_date' => (string)$r['start_date'],
                'end_date'   => (string)$r['end_date'],
                'days'       => (int)$r['days'],
            ];
        }

        return $out;
    }

    // ── private ───────────────────────────────────────────────────────────────

    private function transition(int $id, string $to): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE leave_requests SET status = ? WHERE id = ? AND status = ?'
        );
        $stmt->execute([$to, $id, self::STATUS_PENDING]);

        return $stmt->rowCount() === 1;
    }

    /**
     * @param  array<int,array<string,mixed>> $rows
     * @return array<int,array{id:int,type:string,start_date:string,end_date:string,days:int,status:string}>
     */
    private function hydrateRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'         => (int)$r['id'],
                'type'       => (string)$r['type'],
                'start_date' => (string)$r['start_date'],
                'end_date'   => (string)$r['end_date'],
                'days'       => (int)$r['days'],
                'status'     => (string)$r['status'],
            ];
        }

        return $out;
    }

    private function date(string $value): string
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException("Invalid date (expected Y-m-d): {$value}");
        }

        return $value;
    }

    private function validate(string $value, string $label): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new \InvalidArgumentException("{$label} must not be empty.");
        }

        return $value;
    }

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
