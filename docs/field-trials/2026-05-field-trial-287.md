# Field Trial 287 — ChecksumRegistry

**Date**: 2026-05-29
**Branch**: `feat/ft287-checksum-registry`
**Baseline**: post FT286 merge

## Goal

Add `Nene\Xion\ChecksumRegistry` — a content integrity / tamper-detection registry: store a cryptographic checksum per key and verify content against it later.

## What was built

### `Nene\Xion\ChecksumRegistry` (`class/xion/ChecksumRegistry.php`)

| Method | Description |
| --- | --- |
| `put(key, content, algo='sha256'): string` | Hash + store; returns checksum. |
| `putHash(key, checksum, algo='sha256')` | Store a pre-computed hash. |
| `verify(key, content): bool` | Re-hash and compare (false if unknown). |
| `matches(key, checksum) / get(key) / has(key)` | Compare / read / exists. |
| `forget(key)` | Remove. |

Key design points:

- **Any `hash_algos()` algorithm** (default sha256), validated against the runtime list; `verify` re-hashes with the *stored* algorithm.
- **Timing-safe compare** via `hash_equals`; hex checksums normalised to lowercase so case never causes a false mismatch.
- **Cross-driver upsert**; **PDO injection**.

### Tests (`tests/Unit/Xion/ChecksumRegistryTest.php`)

13 unit tests (20 assertions): put returns sha256, verify match + tamper detect, unknown-key false, idempotent update (old content no longer verifies), putHash + matches, case-insensitive hex, get algo+checksum, has, verify uses stored algo (md5), forget, validation (unknown algo, empty key, empty checksum).

## Findings

### F-1 — No finding (clean trial)

Clean `Nene\Xion` helper. 13 tests pass; CS Fixer and Phan clean.

## Decision

Merge as-is. No follow-up Issues raised.
