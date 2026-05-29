# Field Trial 271 — PasswordPolicy

**Date**: 2026-05-29
**Branch**: `feat/ft271-password-policy`
**Baseline**: post FT270 merge

## Goal

Add `Nene\Xion\PasswordPolicy` — configurable password complexity rules per scope, validating candidate passwords at set-time. Completes the password trio with `PasswordExpiry` (FT264, when to change) and `PasswordHistory` (FT91, no reuse).

## What was built

### `Nene\Xion\PasswordPolicy` (`class/xion/PasswordPolicy.php`)

| Method | Description |
| --- | --- |
| `setPolicy(scope, minLength, requireUpper, requireLower, requireDigit, requireSymbol)` | Upsert a per-scope rule set. |
| `getPolicy(scope): array` | Effective policy (stored row or built-in default). |
| `validate(scope, password): array` | Violation codes (`too_short`/`need_upper`/`need_lower`/`need_digit`/`need_symbol`); empty = valid. |
| `isValid(scope, password): bool` | Convenience over `validate`. |
| `remove(scope)` | Revert a scope to the default. |

Key design points:

- **Safe default**: with no policy row, defaults apply (min length 8, no class requirements), so `validate()` never errors on an unconfigured scope.
- **All violations returned** (not first-fail) so a UI can show every problem at once.
- **Multibyte-correct length** via `mb_strlen`; symbol = any non-alphanumeric (`/[^A-Za-z0-9]/`).
- **Cross-driver upsert**; **PDO injection**.

### Tests (`tests/Unit/Xion/PasswordPolicyTest.php`)

12 unit tests (26 assertions): default-policy behaviour + shape, set/get, all-violations collection, strong-password pass, min-length boundary (9 vs 10 at min 10), symbol detection, multibyte length (パスワード counts as chars), idempotent upsert, remove→default, validation (empty scope, zero min length).

## Findings

### F-1 — No finding (clean trial)

Clean `Nene\Xion` helper. 12 tests pass; CS Fixer and Phan clean.

## Decision

Merge as-is. No follow-up Issues raised.
