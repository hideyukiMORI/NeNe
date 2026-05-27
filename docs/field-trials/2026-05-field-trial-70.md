# Field Trial 70 — Subscription / Plan

**Date**: 2026-05-27
**Branch**: `feat/ft70-subscription`
**Baseline**: post FT69 merge

## Goal

Establish a user subscription / plan management pattern for NeNe applications. Provide lifecycle management (subscribe/change/cancel/renew) with history tracking.

## What was built

### `Nene\Xion\Subscription` (`class/xion/Subscription.php`)

Two-table subscription manager providing:

| Method | Description |
| --- | --- |
| `subscribe(string $userId, string $plan, ?int $expiresIn = null): void` | Upsert subscription. |
| `changePlan(string $userId, string $plan): bool` | Change plan; returns false if no subscription. |
| `cancel(string $userId): bool` | Set cancelled; returns false if already cancelled or missing. |
| `renew(string $userId, ?int $expiresIn = null): bool` | Restore active; returns false if no subscription. |
| `isActive(string $userId): bool` | True if active and not expired. |
| `currentPlan(string $userId): ?string` | Current plan or null. |
| `history(string $userId): array` | Change log, newest first. |

Key design points:

- **One row per user**: `UNIQUE (user_id)` upsert via `INSERT OR REPLACE` / `ON DUPLICATE KEY UPDATE`.
- **History table**: every action (subscribe/change/cancel/renew) writes a history row.
- **`cancel()` idempotent guard**: `WHERE status != 'cancelled'` prevents double-cancel.
- **`isActive()` compound check**: status = 'active' AND (expires_at IS NULL OR expires_at > now).
- **PDO injection**: `__construct(private readonly ?PDO $db = null)`.

### Tests (`tests/Unit/Xion/SubscriptionTest.php`)

23 unit tests covering:

- subscribe creates active subscription
- subscribe sets current plan
- subscribe with expiry stores expires_at
- subscribe replaces existing subscription
- subscribe records history
- isActive false for no subscription
- isActive false for expired subscription
- isActive false for cancelled subscription
- currentPlan returns null for no subscription
- changePlan updates plan
- changePlan returns true on success
- changePlan returns false when no subscription
- changePlan records history
- cancel returns true on success
- cancel returns false when no subscription
- cancel returns false when already cancelled
- cancel records history
- renew activates cancelled subscription
- renew returns true on success
- renew returns false when no subscription
- renew records history
- history returns newest first
- history is user-isolated

### Howto (`docs/development/subscription.md`)

Covers: schema, API table, usage examples, status values, key design points, test patterns.

## Findings

### F-1 — No finding (clean trial)

`Subscription` is a clean `Nene\Xion` helper. 23 tests pass; CS Fixer and Phan clean.

## Decision

Merge as-is. No follow-up Issues raised.
