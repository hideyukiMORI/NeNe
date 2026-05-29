# Field Trial 301 — Pseudonymizer

**Date**: 2026-05-29
**Branch**: `feat/ft301-pseudonymizer`
**Baseline**: post FT300 merge

## Goal

Add `Nene\Kit\Pseudonymizer` — stable real-value → pseudonym token mapping for PII, so logs/exports/analytics can use tokens while still correlating records and authorized callers can re-identify. Distinct from `RedactionRule` (FT279, irreversible text masking) and `ChecksumRegistry` (FT287, integrity hashes).

## What was built

### `Nene\Kit\Pseudonymizer` (`class/kit/Pseudonymizer.php`)

| Method | Description |
| --- | --- |
| `pseudonymize(namespace, value): string` | Stable token (creates one if new). |
| `reverse(namespace, token): ?string` | Re-identify (authorized). |
| `has(namespace, value) / count(namespace)` | Membership / size. |
| `forget(namespace, value)` | GDPR erasure. |

Key design points:

- **Stable + namespaced**: same `(namespace, value)` → same token; namespaces are independent (per export job / context).
- **Concurrency-safe creation**: insert with `ON CONFLICT (namespace, real_value) DO NOTHING` then re-read, so the first writer's token wins.
- **Reversible** within a namespace (unlike redaction); `forget()` erases, after which a fresh token is minted.
- Random 128-bit token (`bin2hex(random_bytes(16))`).

### Tests (`tests/Unit/Kit/PseudonymizerTest.php`)

11 unit tests (19 assertions): stable token (no duplication), distinct values → distinct tokens, reverse + unknown null, namespace independence (same value, scoped reverse), has, forget, forget-then-re-pseudonymize → new token, count per namespace, missing forget no-op, validation (empty namespace/value).

## Findings

### F-1 — No finding (clean trial)

Clean `Nene\Kit` helper. 11 tests pass; CS Fixer and Phan clean.

## Decision

Merge as-is. No follow-up Issues raised.
