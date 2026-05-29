# Field Trial 268 — DataRetention

**Date**: 2026-05-29
**Branch**: `feat/ft268-data-retention`
**Baseline**: post FT267 merge

## Goal

Add `Nene\Xion\DataRetention` — a central table→TTL retention policy registry and purge driver. Many Xion helpers ship their own `purgeOlderThan($days)`; this centralises *how long* each table is kept so one retention cron can drive them all.

## What was built

### `Nene\Xion\DataRetention` (`class/xion/DataRetention.php`)

| Method | Description |
| --- | --- |
| `setPolicy(table, ttlDays)` | Upsert a per-table TTL (idempotent; ttl >= 1). |
| `policyFor(table): ?int` / `policies(): array` | Read one / list all (ordered). |
| `removePolicy(table)` | Drop a policy (no-op if absent). |
| `cutoff(table, asOf=null): ?string` | Compute the stale-before timestamp. |
| `due(asOf=null): array` | Every policy + its computed cutoff, for a cron. |
| `purge(table, dateColumn, asOf=null): int` | Delete rows older than the cutoff; returns row count. |

Key design points:

- **Identifier safety**: `table`/`dateColumn` are developer-supplied identifiers (cannot be bound), validated against `^[A-Za-z_][A-Za-z0-9_]*$` before interpolation; user input never reaches them. Documented trust model.
- **Strictly-older semantics**: `purge` deletes `WHERE col < cutoff`, so a row exactly at the cutoff is kept (matches the half-open convention used elsewhere).
- **Testable time**: `asOf` parameter makes cutoff/purge deterministic without touching the clock.
- **Cross-driver upsert** via `DbUpsert`; **PDO injection**.

### Tests (`tests/Unit/Xion/DataRetentionTest.php`)

14 unit tests (20 assertions): set/get/update/remove/list ordering; cutoff arithmetic (90- and 30-day, annotated); `due()` per-policy cutoffs; `purge` strictly-older boundary (before/at/after cutoff → only "before" removed); purge-without-policy throws; identifier rejection for both table and column (`'; DROP TABLE'`, `col = 1 OR 1`); zero-TTL rejection.

## Findings

### F-1 — No finding (clean trial)

Clean `Nene\Xion` helper. 14 tests pass; CS Fixer and Phan clean.

## Decision

Merge as-is. No follow-up Issues raised.
