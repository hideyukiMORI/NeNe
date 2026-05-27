<?php

declare(strict_types=1);

namespace Nene\Func;

/**
 * Immutable monetary value.
 *
 * Amounts are stored as integers in the smallest currency unit (e.g. ¥100 = 100,
 * $1.50 = 150 cents). This avoids floating-point rounding errors in financial
 * calculations.
 *
 * Arithmetic operations return new instances; the original is never mutated.
 *
 * Currency mismatch throws \InvalidArgumentException — never silently convert.
 *
 * Usage:
 *   $price    = Money::of(1000, 'JPY');
 *   $tax      = $price->multiply(0.1)->round();   // ¥100
 *   $total    = $price->add($tax);                // ¥1100
 *   echo $total->amount();                        // 1100
 *   echo $total->format();                        // "¥1,100"
 */
final readonly class Money
{
    /**
     * @param int    $amount   Amount in the smallest currency unit.
     * @param string $currency ISO 4217 currency code (e.g. 'JPY', 'USD', 'EUR').
     */
    public function __construct(
        private int $amount,
        private string $currency = 'JPY',
    ) {
    }

    /**
     * Named constructor for clarity.
     */
    public static function of(int $amount, string $currency = 'JPY'): self
    {
        return new self($amount, $currency);
    }

    /**
     * Zero-amount money in the given currency.
     */
    public static function zero(string $currency = 'JPY'): self
    {
        return new self(0, $currency);
    }

    public function amount(): int
    {
        return $this->amount;
    }
    public function currency(): string
    {
        return $this->currency;
    }

    /**
     * Add another Money value of the same currency.
     *
     * @throws \InvalidArgumentException on currency mismatch.
     */
    public function add(Money $other): self
    {
        $this->assertSameCurrency($other);
        return new self($this->amount + $other->amount, $this->currency);
    }

    /**
     * Subtract another Money value of the same currency.
     *
     * @throws \InvalidArgumentException on currency mismatch.
     */
    public function subtract(Money $other): self
    {
        $this->assertSameCurrency($other);
        return new self($this->amount - $other->amount, $this->currency);
    }

    /**
     * Multiply by a scalar factor.
     *
     * Returns a new Money with the raw (non-rounded) result truncated to int.
     * For control over rounding, use multiply() followed by round().
     *
     * @param float|int $factor Multiplication factor (e.g. 1.1 for 10% markup).
     */
    public function multiply(float|int $factor): self
    {
        return new self((int) ($this->amount * $factor), $this->currency);
    }

    /**
     * Round a multiply() result using PHP_ROUND_HALF_UP.
     *
     * multiply() truncates; round() can be chained to get a properly rounded value:
     *   Money::of(1000)->multiply(0.1)->round()  // 100 (not 99)
     */
    public function round(int $mode = PHP_ROUND_HALF_UP): self
    {
        return new self((int) round($this->amount, 0, $mode), $this->currency);
    }

    /**
     * Absolute value.
     */
    public function abs(): self
    {
        return new self(abs($this->amount), $this->currency);
    }

    /**
     * Negate (flip sign).
     */
    public function negate(): self
    {
        return new self(-$this->amount, $this->currency);
    }

    public function isZero(): bool
    {
        return $this->amount === 0;
    }
    public function isPositive(): bool
    {
        return $this->amount > 0;
    }
    public function isNegative(): bool
    {
        return $this->amount < 0;
    }

    /**
     * Value equality (same amount AND same currency).
     */
    public function equals(Money $other): bool
    {
        return $this->amount === $other->amount && $this->currency === $other->currency;
    }

    /**
     * Compare amounts (same currency required).
     *
     * @throws \InvalidArgumentException on currency mismatch.
     * @return int -1, 0, or 1
     */
    public function compareTo(Money $other): int
    {
        $this->assertSameCurrency($other);
        return $this->amount <=> $other->amount;
    }

    /**
     * Serialize to an array suitable for JSON encoding.
     *
     * @return array{amount: int, currency: string}
     */
    public function toArray(): array
    {
        return ['amount' => $this->amount, 'currency' => $this->currency];
    }

    /**
     * Format as a human-readable string.
     *
     * Uses simple built-in formatting (no intl extension required):
     *   JPY → "¥1,000"
     *   USD → "$10.00"  (assumes 2 decimal places for non-JPY currencies)
     *   EUR → "€10.00"
     *   GBP → "£10.00"
     *   Other → "1000 XXX"
     */
    public function format(): string
    {
        $symbols = [
            'JPY' => ['symbol' => '¥',  'decimals' => 0],
            'USD' => ['symbol' => '$',  'decimals' => 2],
            'EUR' => ['symbol' => '€',  'decimals' => 2],
            'GBP' => ['symbol' => '£',  'decimals' => 2],
            'CNY' => ['symbol' => '¥',  'decimals' => 2],
            'KRW' => ['symbol' => '₩',  'decimals' => 0],
        ];

        if (!isset($symbols[$this->currency])) {
            return number_format($this->amount) . ' ' . $this->currency;
        }

        $config = $symbols[$this->currency];
        $value  = $this->amount;

        if ($config['decimals'] > 0) {
            $value = $this->amount / (10 ** $config['decimals']);
            return $config['symbol'] . number_format($value, $config['decimals']);
        }

        return $config['symbol'] . number_format($value);
    }

    // -----------------------------------------------------------------------

    private function assertSameCurrency(Money $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new \InvalidArgumentException(
                "Currency mismatch: {$this->currency} vs {$other->currency}"
            );
        }
    }
}
