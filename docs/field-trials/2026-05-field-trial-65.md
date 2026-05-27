# Field Trial 65 — Personal Access Token Management

**Date**: 2026-05-27
**Branch**: `feat/ft65-personal-access-token`
**Baseline**: post FT64 merge

## Goal

Establish a user-managed Personal Access Token (PAT) pattern for NeNe applications. Provide ability-based authorization and `last_used_at` tracking for user-facing dashboard credential management.

## What was built

### `Nene\Xion\PersonalAccessToken` (`class/xion/PersonalAccessToken.php`)

DB-backed PAT manager providing:

| Method | Description |
| --- | --- |
| `create(string $userId, string $name = '', array\|string $abilities = '*', ?int $expiresIn = null): array{rawToken: string, id: int}` | Create token. Returns raw token once. |
| `authenticate(string $rawToken, string $requiredAbility = '*'): ?array` | Authenticate + update last_used_at. |
| `list(string $userId): array` | Active tokens (no token_hash). |
| `revoke(int $tokenId, string $userId): bool` | Owner-enforced revocation. |

Key design points:

- **Token format**: `pat_{base64url(32 bytes)}` — 256-bit entropy, prefix-differentiated from `nk_` API keys.
- **Ability model**: JSON array stored as TEXT or literal `'*'` wildcard. `authenticate()` checks for `'*'` in array or exact ability match.
- **last_used_at**: updated inside `authenticate()` on success — no separate call needed.
- **Hash storage**: SHA-256; prefix 16-char lookup; raw token shown once.
- **token_hash never exposed**: neither `authenticate()` nor `list()` results include it.
- **PDO injection**: `__construct(private readonly ?PDO $db = null)`.

### Tests (`tests/Unit/Xion/PersonalAccessTokenTest.php`)

24 unit tests covering:

- create returns rawToken and id
- created token starts with `pat_`
- create with name
- create with abilities array
- create with wildcard ability string
- create with expiry stores expires_at
- authenticate valid token returns record
- authenticate empty token returns null
- authenticate invalid token returns null
- authenticate revoked token returns null
- authenticate expired token returns null
- authenticate updates last_used_at
- token_hash never exposed in auth result
- wildcard ability satisfies any requirement
- specific ability satisfies exact match
- specific ability fails missing ability
- wildcard in abilities array satisfies all
- list returns only owner tokens
- list excludes revoked tokens
- list does not expose token_hash
- list initial last_used_at is null
- revoke by correct owner returns true
- revoke by wrong owner returns false
- revoke already revoked returns false

### Howto (`docs/development/personal-access-token.md`)

Covers: schema, API table, usage examples, abilities model, comparison vs ApiKey (FT56), key design points, test patterns.

## Findings

### F-1 — No finding (clean trial)

`PersonalAccessToken` is a clean `Nene\Xion` helper. 24 tests pass; CS Fixer and Phan clean.

## Decision

Merge as-is. No follow-up Issues raised.
