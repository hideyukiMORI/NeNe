<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * ExchangeRate — effective-dated currency conversion rate table.
 *
 * Stores fixed-point conversion rates between currency pairs with an
 * effective date, and converts integer-minor-unit amounts (cents) without
 * floating point. Complements `Money` (FT49) and `TaxRate` (FT207): same
 * integer-cent discipline, no `DECIMAL`/floats in the database.
 *
 * Rates are stored as integers scaled by {@see ExchangeRate::SCALE} (1e6, i.e.
 * six decimal places). A rate of `1_500_000` means "× 1.5"; `150_000_000`
 * means "× 150". `convertCents()` multiplies the amount by `rate / SCALE` and
 * rounds half-up to the nearest minor unit. The caller is responsible for
 * consistent minor-unit interpretation across the pair.
 *
 * ## Usage
 *
 * ```php
 * $fx = new ExchangeRate($pdo);
 *
 * // 1 USD = 1.5 (e.g. some quote currency); effective from a date
 * $fx->setRate('USD', 'AUD', 1_500_000, '2026-01-01');
 * $fx->setRate('USD', 'AUD', 1_550_000, '2026-02-01'); // later revision
 *
 * // Most recent rate on or before a date
 * $fx->rateAt('USD', 'AUD', '2026-01-15'); // 1_500_000
 * $fx->rateAt('USD', 'AUD', '2026-02-10'); // 1_550_000
 * $fx->latest('USD', 'AUD');               // 1_550_000
 *
 * // Convert an integer-cent amount (returns null if no applicable rate)
 * $fx->convertCents('USD', 'AUD', 1000, '2026-01-15'); // 1500
 * $fx->convertCents('USD', 'AUD', 1000);               // uses latest → 1550
 *
 * $fx->history('USD', 'AUD'); // [['date'=>'2026-02-01','rate'=>1550000], ...]
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE exchange_rates (
 *     id             INTEGER PRIMARY KEY AUTOINCREMENT,
 *     base           CHAR(3)  NOT NULL,
 *     quote          CHAR(3)  NOT NULL,
 *     rate           BIGINT   NOT NULL,
 *     effective_date CHAR(10) NOT NULL,
 *     created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (base, quote, effective_date)
 * );
 * ```
 */
final class ExchangeRate
{
    /** Fixed-point scale for stored rates (six decimal places). */
    public const int SCALE = 1_000_000;

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Set (or replace) the rate for a pair effective from a date.
     *
     * Idempotent per (base, quote, effective_date) — re-setting the same date
     * overwrites the rate via cross-driver upsert.
     *
     * @param  string $base          Base currency code (e.g. 'USD').
     * @param  string $quote         Quote currency code (e.g. 'AUD').
     * @param  int    $rate          Rate scaled by SCALE (must be > 0).
     * @param  string $effectiveDate Effective date 'Y-m-d'.
     * @throws \InvalidArgumentException on empty code, $rate <= 0, or bad date.
     */
    public function setRate(string $base, string $quote, int $rate, string $effectiveDate): void
    {
        $base  = $this->validateCode($base);
        $quote = $this->validateCode($quote);
        if ($rate <= 0) {
            throw new \InvalidArgumentException('Rate must be positive.');
        }
        $effectiveDate = $this->normalizeDate($effectiveDate);

        DbUpsert::run(
            $this->db(),
            table:        'exchange_rates',
            data:         ['base' => $base, 'quote' => $quote, 'rate' => $rate, 'effective_date' => $effectiveDate],
            conflictCols: ['base', 'quote', 'effective_date'],
            updateCols:   ['rate'],
        );
    }

    /**
     * Return the rate in effect on or before a date (most recent applicable).
     *
     * @param  string $base  Base currency code.
     * @param  string $quote Quote currency code.
     * @param  string $date  As-of date 'Y-m-d'.
     * @return int|null      Scaled rate, or null if no rate is effective yet.
     */
    public function rateAt(string $base, string $quote, string $date): ?int
    {
        $base  = $this->validateCode($base);
        $quote = $this->validateCode($quote);
        $date  = $this->normalizeDate($date);

        $stmt = $this->db()->prepare(
            'SELECT rate FROM exchange_rates
             WHERE base = ? AND quote = ? AND effective_date <= ?
             ORDER BY effective_date DESC LIMIT 1'
        );
        $stmt->execute([$base, $quote, $date]);
        $rate = $stmt->fetchColumn();

        return $rate === false ? null : (int)$rate;
    }

    /**
     * Return the most recent rate for a pair regardless of date.
     *
     * @param  string $base  Base currency code.
     * @param  string $quote Quote currency code.
     * @return int|null      Scaled rate, or null if the pair has no rates.
     */
    public function latest(string $base, string $quote): ?int
    {
        $base  = $this->validateCode($base);
        $quote = $this->validateCode($quote);

        $stmt = $this->db()->prepare(
            'SELECT rate FROM exchange_rates
             WHERE base = ? AND quote = ?
             ORDER BY effective_date DESC LIMIT 1'
        );
        $stmt->execute([$base, $quote]);
        $rate = $stmt->fetchColumn();

        return $rate === false ? null : (int)$rate;
    }

    /**
     * Convert an integer-minor-unit amount using the applicable rate.
     *
     * Uses the rate effective on $date (most recent on or before), or the
     * latest rate when $date is null. Rounds half-up to the nearest minor unit.
     *
     * @param  string      $base   Base currency code.
     * @param  string      $quote  Quote currency code.
     * @param  int         $amount Amount in base minor units (e.g. cents).
     * @param  string|null $date   As-of date 'Y-m-d', or null for the latest rate.
     * @return int|null            Converted amount in quote minor units, or null
     *                             if no applicable rate exists.
     */
    public function convertCents(string $base, string $quote, int $amount, ?string $date = null): ?int
    {
        $rate = $date === null ? $this->latest($base, $quote) : $this->rateAt($base, $quote, $date);
        if ($rate === null) {
            return null;
        }

        $sign     = $amount < 0 ? -1 : 1;
        $product  = abs($amount) * $rate;
        $rounded  = intdiv($product + intdiv(self::SCALE, 2), self::SCALE);

        return $sign * $rounded;
    }

    /**
     * Return the full rate history for a pair, newest effective date first.
     *
     * @param  string $base  Base currency code.
     * @param  string $quote Quote currency code.
     * @return array<int,array{date:string,rate:int}>
     */
    public function history(string $base, string $quote): array
    {
        $base  = $this->validateCode($base);
        $quote = $this->validateCode($quote);

        $stmt = $this->db()->prepare(
            'SELECT effective_date, rate FROM exchange_rates
             WHERE base = ? AND quote = ?
             ORDER BY effective_date DESC'
        );
        $stmt->execute([$base, $quote]);

        $out = [];
        /** @var array<string,mixed> $row */
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = ['date' => (string)$row['effective_date'], 'rate' => (int)$row['rate']];
        }

        return $out;
    }

    // ── private ───────────────────────────────────────────────────────────────

    private function validateCode(string $code): string
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            throw new \InvalidArgumentException('Currency code must not be empty.');
        }

        return $code;
    }

    private function normalizeDate(string $date): string
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException("Invalid date (expected Y-m-d): {$date}");
        }

        return $parsed->format('Y-m-d');
    }

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
