# Field Trial 267 — ExchangeRate

**Date**: 2026-05-29
**Branch**: `feat/ft267-exchange-rate`
**Baseline**: post FT266 merge

## Goal

Add `Nene\Xion\ExchangeRate` — effective-dated currency conversion rates with integer-cent conversion and no floating point. Rounds out the money helpers alongside `Money` (FT49) and `TaxRate` (FT207).

## What was built

### `Nene\Xion\ExchangeRate` (`class/xion/ExchangeRate.php`)

Stores fixed-point rates per directed currency pair with an effective date. Rates are integers scaled by `SCALE = 1_000_000` (six decimal places); `1_500_000` means "× 1.5".

| Method | Description |
| --- | --- |
| `setRate(base, quote, rate, effectiveDate)` | Upsert a scaled rate effective from a date (idempotent per date). |
| `rateAt(base, quote, date): ?int` | Most recent rate on or before a date. |
| `latest(base, quote): ?int` | Most recent rate regardless of date. |
| `convertCents(base, quote, amount, date=null): ?int` | Convert minor units; half-up rounding; null if no rate. |
| `history(base, quote): array` | Full rate history, newest effective date first. |

Key design points:

- **Integer-only fixed point**: rates stored as scaled `BIGINT`; `convertCents` uses `intdiv(amount*rate + SCALE/2, SCALE)` for half-up rounding, sign-corrected for negatives — no floats, consistent with the monetary-cents policy.
- **Effective dating**: `rateAt` selects `effective_date <= date ORDER BY effective_date DESC LIMIT 1`, so historical conversions are reproducible; `convertCents(..., date=null)` uses `latest()`.
- **Directed pairs**: `(base, quote)` is directional; the reverse pair is not implied (avoids silent precision loss from inversion).
- **Round-trip date validation**; **cross-driver upsert** via `DbUpsert`; **PDO injection**.

### Tests (`tests/Unit/Xion/ExchangeRateTest.php`)

19 unit tests (27 assertions): rateAt boundary (on/before/after revision, +1-day-before-effective null), latest, idempotent per-date overwrite, code upper-normalisation, directional pairs, convertCents (with date / latest / half-up / below-half / negative / zero / null-when-no-rate), history ordering, and validation (non-positive rate, empty code, overflow date).

## Findings

### F-1 — No finding (clean trial)

Clean `Nene\Xion` helper. 19 tests pass; CS Fixer and Phan clean.

## Decision

Merge as-is. No follow-up Issues raised.
