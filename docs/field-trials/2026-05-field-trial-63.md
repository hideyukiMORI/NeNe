# Field Trial 63 — User Follow System

**Date**: 2026-05-27
**Branch**: `feat/ft63-follow-relation`
**Baseline**: post FT56–FT62 merge wave

## Goal

Establish a user follow/unfollow relationship pattern for NeNe applications. Provide a DB-backed helper that manages directed follower–followee relationships with mutual-follow detection and self-follow prevention.

## What was built

### `Nene\Xion\FollowRelation` (`class/xion/FollowRelation.php`)

DB-backed follow relationship manager providing:

| Method | Description |
| --- | --- |
| `follow(string $followerId, string $followeeId): bool` | Follow a user. Returns `false` if already following or self-follow. |
| `unfollow(string $followerId, string $followeeId): bool` | Unfollow a user. Returns `false` if not following. |
| `isFollowing(string $followerId, string $followeeId): bool` | Directional follow-status check. |
| `followers(string $userId): array` | List users following $userId. |
| `following(string $userId): array` | List users $userId follows. |
| `isMutual(string $userA, string $userB): bool` | Check mutual follow (both directions). |

Key design points:

- **Composite PK**: `(follower_id, followee_id)` ensures uniqueness and serves as the lookup index.
- **Self-follow prevention**: Application-layer guard — returns `false` without DB access.
- **Idempotent follow**: `INSERT OR IGNORE` / `INSERT IGNORE`; duplicate returns `false` via `rowCount()`.
- **Directional semantics**: A→B and B→A are independent rows.
- **PDO injection**: `__construct(private readonly ?PDO $db = null)` — falls back to `PdoConnection::getInstance()`.

### Tests (`tests/Unit/Xion/FollowRelationTest.php`)

20 unit tests covering:

- follow returns true on first call
- follow returns false on duplicate
- follow returns false for self-follow
- unfollow returns true when following
- unfollow returns false when not following
- unfollow removes relationship
- isFollowing true after follow
- isFollowing false before follow
- isFollowing is directional (A→B ≠ B→A)
- followers returns users following target
- followers returns empty when none
- followers result shape (follower_id, created_at)
- following returns users followed by target
- following returns empty when none
- following result shape (followee_id, created_at)
- isMutual true when both follow
- isMutual false when only one follows
- isMutual false when neither follows
- isMutual is symmetric
- followers only returns followers of specified user

### Howto (`docs/development/follow-relation.md`)

Covers: schema, API table, usage examples, key design points, test patterns.

## Findings

### F-1 — No finding (clean trial)

`FollowRelation` is a clean `Nene\Xion` helper with PDO injection and no framework coupling. 20 tests pass; CS Fixer and Phan clean.

## Decision

Merge as-is. No follow-up Issues raised.
