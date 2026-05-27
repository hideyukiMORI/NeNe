<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * Recurring payment schedule management.
 *
 * Tracks subscription-billing schedules: amount, currency, frequency, and the
 * next billing date.  A cron job calls due() to retrieve overdue schedules and
 * then recordBilling() after charging to advance the next billing date.
 *
 * All monetary amounts are stored as integer cents (e.g. 999 = $9.99).
 *
 * ## Usage
 *
 * ```php
 * $rp = new RecurringPayment($pdo);
 *
 * // Set up a monthly $9.99 charge starting 2026-06-01
 * $id = $rp->schedule('user-1', 999, 'USD', 'monthly', '2026-06-01');
 *
 * // Billing cron: find due schedules and process them
 * foreach ($rp->due() as $row) {
 *     charge($row);                    // your payment gateway call
 *     $rp->recordBilling($row['id']);  // advance next_billing_date
 * }
 *
 * // Pause / resume / cancel
 * $rp->pause($id);
 * $rp->resume($id);
 * $rp->cancel($id);
 * ```
 *
 * ## Frequencies
 *
 * `daily`, `weekly`, `monthly`, `yearly`
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE recurring_payments (
 *     id                INTEGER PRIMARY KEY AUTOINCREMENT,
 *     user_id           VARCHAR(255) NOT NULL,
 *     amount_cents      INT          NOT NULL,
 *     currency          CHAR(3)      NOT NULL DEFAULT 'USD',
 *     frequency         VARCHAR(20)  NOT NULL,
 *     next_billing_date DATE         NOT NULL,
 *     last_billed_date  DATE         NULL,
 *     active            TINYINT(1)   NOT NULL DEFAULT 1,
 *     created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class RecurringPayment
{
    private const FREQUENCIES = ['daily', 'weekly', 'monthly', 'yearly'];

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── private helpers ───────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    /** Advance a date string by one frequency period. */
    private function advance(string $date, string $frequency): string
    {
        $dt = new \DateTimeImmutable($date);

        return match ($frequency) {
            'daily'   => $dt->modify('+1 day')->format('Y-m-d'),
            'weekly'  => $dt->modify('+1 week')->format('Y-m-d'),
            'monthly' => $dt->modify('+1 month')->format('Y-m-d'),
            'yearly'  => $dt->modify('+1 year')->format('Y-m-d'),
            default   => $date,
        };
    }

    // ── schedule ──────────────────────────────────────────────────────────────

    /**
     * Create a new recurring payment schedule.
     *
     * @param  int    $amountCents  Amount to charge in integer cents (must be > 0).
     * @param  string $frequency    One of: daily, weekly, monthly, yearly.
     * @param  string $startDate    Y-m-d date for the first billing.
     * @return int                  New schedule ID.
     * @throws \InvalidArgumentException on invalid input.
     */
    public function schedule(
        string $userId,
        int $amountCents,
        string $currency,
        string $frequency,
        string $startDate,
    ): int {
        if ($userId === '') {
            throw new \InvalidArgumentException('userId must not be empty.');
        }

        if ($amountCents <= 0) {
            throw new \InvalidArgumentException('amountCents must be > 0.');
        }

        if ($currency === '') {
            throw new \InvalidArgumentException('currency must not be empty.');
        }

        if (!in_array($frequency, self::FREQUENCIES, true)) {
            throw new \InvalidArgumentException(
                'frequency must be one of: ' . implode(', ', self::FREQUENCIES) . '.'
            );
        }

        $stmt = $this->db()->prepare(
            'INSERT INTO recurring_payments
             (user_id, amount_cents, currency, frequency, next_billing_date)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $amountCents, $currency, $frequency, $startDate]);
        return (int)$this->db()->lastInsertId();
    }

    // ── get ───────────────────────────────────────────────────────────────────

    /**
     * Retrieve a schedule row.
     *
     * @return array<string,mixed>|null
     */
    public function get(int $id): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM recurring_payments WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    // ── due ───────────────────────────────────────────────────────────────────

    /**
     * Return active schedules whose next billing date is on or before $asOf.
     *
     * @return array<int,array<string,mixed>>
     */
    public function due(?string $asOf = null): array
    {
        $date = $asOf ?? date('Y-m-d');
        $stmt = $this->db()->prepare(
            'SELECT * FROM recurring_payments
             WHERE active = 1 AND next_billing_date <= ?
             ORDER BY next_billing_date ASC'
        );
        $stmt->execute([$date]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── recordBilling ─────────────────────────────────────────────────────────

    /**
     * Mark a billing as completed and advance next_billing_date by one period.
     *
     * Returns false if the schedule does not exist.
     */
    public function recordBilling(int $id, ?string $billedDate = null): bool
    {
        $row = $this->get($id);

        if ($row === null) {
            return false;
        }

        $today    = $billedDate ?? date('Y-m-d');
        $nextDate = $this->advance((string)$row['next_billing_date'], (string)$row['frequency']);

        $stmt = $this->db()->prepare(
            'UPDATE recurring_payments
             SET last_billed_date  = ?,
                 next_billing_date = ?,
                 updated_at        = CURRENT_TIMESTAMP
             WHERE id = ?'
        );
        $stmt->execute([$today, $nextDate, $id]);
        return $stmt->rowCount() > 0;
    }

    // ── pause / resume / cancel ───────────────────────────────────────────────

    /**
     * Pause billing (active = 0) without deleting the schedule.
     */
    public function pause(int $id): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE recurring_payments SET active = 0, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
        );
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Resume a paused schedule.
     */
    public function resume(int $id): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE recurring_payments SET active = 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
        );
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Permanently delete a schedule.
     */
    public function cancel(int $id): bool
    {
        $stmt = $this->db()->prepare('DELETE FROM recurring_payments WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    // ── forUser ───────────────────────────────────────────────────────────────

    /**
     * Return all schedules (active and paused) for a user.
     *
     * @return array<int,array<string,mixed>>
     */
    public function forUser(string $userId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM recurring_payments WHERE user_id = ? ORDER BY id ASC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
