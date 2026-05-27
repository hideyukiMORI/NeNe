# Field Trial 49 — Money Value Object

**Date:** 2026-05-27
**Theme:** Immutable monetary value object — safe integer arithmetic for e-commerce, billing, and invoicing
**Issue:** #ft49
**PR:** (pending)

---

## What was built

A `Nene\Func\Money` final readonly class representing an immutable monetary
amount stored as an integer in the smallest currency unit (yen, cents, etc.).
Arithmetic operations return new instances; the original is never mutated.
Currency mismatch throws `\InvalidArgumentException` — there is no silent
conversion.

### New framework class

**`class/func/Money.php`** — immutable monetary value object:

| Method | Description |
|---|---|
| `Money::of(int, string): self` | Named constructor |
| `Money::zero(string): self` | Zero-amount factory |
| `amount(): int` | Raw integer amount |
| `currency(): string` | ISO 4217 currency code |
| `add(Money): self` | Add (same currency required) |
| `subtract(Money): self` | Subtract (same currency required) |
| `multiply(float\|int): self` | Multiply by scalar (truncates) |
| `round(int $mode): self` | Round result (chain after `multiply()`) |
| `abs(): self` | Absolute value |
| `negate(): self` | Flip sign |
| `isZero(): bool` | Predicate |
| `isPositive(): bool` | Predicate |
| `isNegative(): bool` | Predicate |
| `equals(Money): bool` | Value equality (amount + currency) |
| `compareTo(Money): int` | -1 / 0 / 1 spaceship comparison |
| `toArray(): array` | `{amount, currency}` for JSON |
| `format(): string` | Human-readable (¥1,000 / $1.50 / etc.) |

### Tests

**`tests/Unit/Func/MoneyTest.php`** — 28 tests, 34 assertions covering:
- Construction via `of()` and `zero()`
- `add()`, `subtract()`, `multiply()` (int and float factors)
- `round()` chained after `multiply()` to correct float truncation
- `negate()`, `abs()` on positive and negative amounts
- `isZero()`, `isPositive()`, `isNegative()` predicates
- `equals()` — true, false by amount, false by currency
- `compareTo()` — less than, equal, greater than
- Exception tests for `add()`, `subtract()`, `compareTo()` on currency mismatch
- `toArray()` structure
- `format()` for JPY, USD, and unknown currency
- Immutability: original unchanged after `add()`

### Documentation

**`docs/development/money.md`** — full developer guide covering:
- Why integer storage (floating-point hazards in financial code)
- API reference table
- Common patterns: price + tax, discount, accumulation loop
- Currency conversion note (out of scope — explicit by design)
- JSON serialization with `toArray()`
- `format()` behaviour per currency
- Using in Mapper (schema as INT, wrapping in Mapper)
- Related: FT26 soft-delete (refund/reversal workflows)

---

## Findings

### F-1 — `multiply()` truncates; `round()` must be chained explicitly (design decision)

`multiply()` uses `(int) ($this->amount * $factor)` which truncates toward
zero. This is intentional: the caller decides when and how to round. However,
floating-point representation means `1000 * 0.108` produces `107.999…` in
float, which truncates to 107, not 108.

**Resolution:** `round()` is a chainable method that applies PHP's
`round($amount, 0, $mode)` with `PHP_ROUND_HALF_UP` as default. The pattern
`->multiply(0.108)->round()` yields 108 as expected. This is documented in the
class docblock and in `docs/development/money.md`.

The `testRoundAfterMultiply` test specifically covers this edge case.

### F-2 — `format()` requires no `intl` extension (design note)

The built-in `NumberFormatter` from the `intl` extension provides
locale-aware formatting but adds a dependency that is not guaranteed in all
NeNe deployment environments. `format()` uses `number_format()` with a static
symbol table instead. This covers the six most common currencies in the project
context (JPY, USD, EUR, GBP, CNY, KRW) and falls back to `"100 XYZ"` for
unknown codes.

If locale-aware formatting is needed later, it can be added as a separate
`formatLocale(string $locale): string` method without breaking the existing API.

### F-3 — Vendor symlink in worktree caused class-not-found failures

The worktree was initialised with `vendor` as a symlink to the main repo's
`vendor/`. The Composer autoloader's `$baseDir` is relative to the vendor
directory's real path, so it resolved `Nene\Func\Money` to the main repo's
`class/func/`, not the worktree's. Tests failed with `Class "Nene\Func\Money"
not found`.

**Fix:** Removed the symlink and ran `composer install --no-interaction` in the
worktree to produce a local vendor with the correct `$baseDir`.

---

## Results

| Check | Result |
|---|---|
| PHPUnit (unit) | 250 tests, 446 assertions — OK |
| Phan | 0 errors (exit 0) |
| PHP syntax | No errors |

---

## Related

- `class/func/Money.php` — implementation
- `tests/Unit/Func/MoneyTest.php` — unit tests
- `docs/development/money.md` — developer guide
- FT26 soft-delete — refund/reversal workflows use `negate()` pattern
