# Field Trial 282 — AffiliateClick

**Date**: 2026-05-29
**Branch**: `feat/ft282-affiliate-click`
**Baseline**: post FT281 merge

## Goal

Add `Nene\Xion\AffiliateClick` — affiliate click tracking with conversion attribution and integer-cent revenue, for partner-program payouts and conversion-rate reporting. Distinct from `Referral` (FT85, user-to-user codes).

## What was built

### `Nene\Xion\AffiliateClick` (`class/xion/AffiliateClick.php`)

| Method | Description |
| --- | --- |
| `recordClick(affiliate, clickId, landing='')` | Log a click (idempotent per click id). |
| `convert(clickId, valueCents=0): bool` | Mark converted; true only on the first conversion. |
| `isConverted(clickId)` | Conversion check. |
| `clicksFor / conversionsFor / revenueFor (affiliate)` | Counts / cents. |
| `stats(affiliate)` | `{clicks, conversions, revenue, rate}`. |
| `purgeOlderThan(days, asOf=null)` | Housekeeping. |

Key design points:

- **Idempotent clicks** via `ON CONFLICT DO NOTHING` (unique click id); **convert() guarded** with `WHERE converted = 0` so a re-conversion returns false and never overwrites the recorded value.
- **Integer-cent revenue** (no floats); `rate` rounded to 4 dp, 0.0 when no clicks.
- **PDO injection**; `asOf` for deterministic time.

### Tests (`tests/Unit/Xion/AffiliateClickTest.php`)

13 unit tests (24 assertions): record + count, idempotent click, convert + revenue, convert unknown false, **double-convert returns false + value unchanged**, conversions/revenue sums, stats rate (0.25), zero-clicks rate 0.0, affiliate separation, purgeOlderThan, validation (negative value, empty affiliate/click id).

## Findings

### F-1 — No finding (clean trial)

Clean `Nene\Xion` helper. 13 tests pass; CS Fixer and Phan clean.

## Decision

Merge as-is. No follow-up Issues raised.
