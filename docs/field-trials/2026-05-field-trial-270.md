# Field Trial 270 — EmailSuppression

**Date**: 2026-05-29
**Branch**: `feat/ft270-email-suppression`
**Baseline**: post FT269 merge

## Goal

Add `Nene\Xion\EmailSuppression` — a do-not-send list (bounce / complaint / unsubscribe / manual) consulted before dispatch to protect deliverability. Complements `EmailBounce` (FT253, raw bounce events): this is the deduplicated suppression set keyed by address.

## What was built

### `Nene\Xion\EmailSuppression` (`class/xion/EmailSuppression.php`)

| Method | Description |
| --- | --- |
| `suppress(email, reason=MANUAL)` | Add/update (idempotent per address; validated reason). |
| `isSuppressed(email) / reasonFor(email)` | Case-insensitive lookup. |
| `release(email)` | Remove (recovery / re-subscribe). |
| `filter(emails[]): array` | Keep only deliverable addresses (single `IN (...)` query, order + casing preserved). |
| `all(reason=null): array` | List, optionally by reason, newest first. |

Key design points:

- **Normalisation**: addresses lowercased + trimmed on every path so lookups are case-insensitive; `UNIQUE (email)` dedupes.
- **`filter()` for the send pipeline**: one query for the whole batch (not N), preserves input order and original casing of kept addresses, drops blanks/dupes internally.
- **Reason constants** validated against an allow-list; **cross-driver upsert**; **PDO injection**.

### Tests (`tests/Unit/Xion/EmailSuppressionTest.php`)

14 unit tests (19 assertions): suppress/check, case-insensitive + trim, default reason, idempotent reason-update, not-suppressed null, release (+ case-insensitive, + missing no-op), `filter` (deliverable kept with order/casing, empty list, blank entries dropped), `all` by reason, and validation (unknown reason on suppress + all, empty email).

## Findings

### F-1 — No finding (clean trial)

Clean `Nene\Xion` helper. 14 tests pass; CS Fixer and Phan clean.

## Decision

Merge as-is. No follow-up Issues raised.
