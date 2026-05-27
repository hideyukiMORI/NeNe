# Field Trial 64 — User Preferences / Settings

**Date**: 2026-05-27
**Branch**: `feat/ft64-user-preference`
**Baseline**: post FT63 merge

## Goal

Establish a user-preference persistence pattern for NeNe applications. Provide a key-value store with default fallback and type casting so applications can persist per-user settings without a dedicated ORM.

## What was built

### `Nene\Xion\UserPreference` (`class/xion/UserPreference.php`)

DB-backed key-value preference store providing:

| Method | Description |
| --- | --- |
| `get(string $userId, string $key, ?string $default = null): ?string` | Get as string with default fallback. |
| `getInt(string $userId, string $key, int $default = 0): int` | Get cast to int. |
| `getBool(string $userId, string $key, bool $default = false): bool` | Get cast to bool. |
| `set(string $userId, string $key, string $value): void` | Upsert a preference. |
| `delete(string $userId, string $key): bool` | Delete; returns true if row existed. |
| `all(string $userId): array<string, string>` | All preferences as key→value map. |

Key design points:

- **Upsert pattern**: `INSERT OR REPLACE` (SQLite) / `INSERT … ON DUPLICATE KEY UPDATE` (MySQL).
- **Type casting at application layer**: `TEXT` column stores all values as strings; `getInt()`/`getBool()` convert on read.
- **Boolean conventions**: `getBool()` truthy values: `'1'`, `'true'`, `'yes'` (case-insensitive).
- **User isolation**: all queries scoped by `user_id`.
- **PDO injection**: `__construct(private readonly ?PDO $db = null)`.

### Tests (`tests/Unit/Xion/UserPreferenceTest.php`)

20 unit tests covering:

- get returns stored value
- get returns null default when key not set
- get returns custom default when key not set
- get is user-isolated
- set overwrites existing value
- set multiple keys
- getInt returns stored value as int
- getInt returns default when key not set
- getInt default is zero when not specified
- getBool returns true for '1'
- getBool returns true for 'true'
- getBool returns true for 'yes'
- getBool returns false for '0'
- getBool returns default when key not set
- delete returns true when key exists
- delete returns false when key not set
- delete reverts to default
- all returns key-value map
- all returns empty map when no prefs
- all is user-isolated

### Howto (`docs/development/user-preference.md`)

Covers: schema, API table, usage examples, boolean storage conventions, key design points, test patterns.

## Findings

### F-1 — No finding (clean trial)

`UserPreference` is a clean `Nene\Xion` helper with upsert and PDO injection. 20 tests pass; CS Fixer and Phan clean.

## Decision

Merge as-is. No follow-up Issues raised.
