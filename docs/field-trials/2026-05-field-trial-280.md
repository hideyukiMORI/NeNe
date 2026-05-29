# Field Trial 280 — IpReputation

**Date**: 2026-05-29
**Branch**: `feat/ft280-ip-reputation`
**Baseline**: post FT279 merge

## Goal

Add `Nene\Xion\IpReputation` — a running reputation score per IP from observed behaviour. The soft, additive signal that can feed a blocklist decision; distinct from `IpAllowlist`/`IpBlocklist` (FT121/FT148) hard binary lists.

## What was built

### `Nene\Xion\IpReputation` (`class/xion/IpReputation.php`)

Higher score = worse reputation.

| Method | Description |
| --- | --- |
| `adjust(ip, delta): int` | Atomic add (may be negative); returns new score. |
| `penalize(ip, points=1) / reward(ip, points=1)` | Convenience +/- (points >= 1). |
| `score(ip) / isBad(ip, threshold)` | Read / threshold check (>= inclusive). |
| `worst(limit=10)` | Worst offenders, highest first. |
| `reset(ip) / remove(ip) / purgeBelow(threshold)` | Zero / delete / decay housekeeping. |

Key design points:

- **Atomic accumulation**: `adjust` does read-modify-write inside a transaction (reuses an open one), so concurrent observers don't lose increments.
- **Feeds, not replaces, blocklists**: a soft score; the app decides when a threshold escalates to a hard block.
- **Cross-driver upsert**; **PDO injection**.

### Tests (`tests/Unit/Xion/IpReputationTest.php`)

13 unit tests (21 assertions): penalize accumulation, reward decrease, negative adjust, unknown→0, isBad threshold (inclusive boundary), worst desc + limit, reset, remove, purgeBelow, adjust within an existing transaction, validation (zero penalize/reward points, empty IP).

## Findings

### F-1 — No finding (clean trial)

Clean `Nene\Xion` helper. 13 tests pass; CS Fixer and Phan clean.

## Decision

Merge as-is. No follow-up Issues raised.
