# Follow Relation

`Nene\Kit\FollowRelation` — directed user follow/unfollow relationships with mutual-follow detection.

## Schema

```sql
CREATE TABLE follow_relations (
    follower_id VARCHAR(255) NOT NULL,
    followee_id VARCHAR(255) NOT NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (follower_id, followee_id)
);
CREATE INDEX idx_follow_relations_followee ON follow_relations (followee_id);
```

The composite primary key `(follower_id, followee_id)` ensures uniqueness and serves as the main lookup index. The secondary index on `followee_id` speeds up `followers()` queries.

## API

| Method | Description |
| --- | --- |
| `follow(string $followerId, string $followeeId): bool` | Follow a user. Returns `true` on success, `false` if already following or self-follow attempt. |
| `unfollow(string $followerId, string $followeeId): bool` | Unfollow a user. Returns `true` if removed, `false` if not following. |
| `isFollowing(string $followerId, string $followeeId): bool` | Check whether $followerId follows $followeeId. |
| `followers(string $userId): array` | List users following $userId. |
| `following(string $userId): array` | List users $userId follows. |
| `isMutual(string $userA, string $userB): bool` | Check whether both users follow each other. |

## Usage

```php
$fr = new FollowRelation($pdo);

// Follow
$fr->follow('user:1', 'user:2');   // true
$fr->follow('user:1', 'user:2');   // false — already following
$fr->follow('user:1', 'user:1');   // false — self-follow

// Check
$fr->isFollowing('user:1', 'user:2');  // true
$fr->isFollowing('user:2', 'user:1');  // false — directional

// Lists
$fr->followers('user:2');   // [['follower_id' => 'user:1', 'created_at' => '...']]
$fr->following('user:1');   // [['followee_id' => 'user:2', 'created_at' => '...']]

// Mutual follow
$fr->follow('user:2', 'user:1');
$fr->isMutual('user:1', 'user:2');  // true

// Unfollow
$fr->unfollow('user:1', 'user:2');  // true
$fr->unfollow('user:1', 'user:2');  // false — not following
```

## Key design points

- **Self-follow prevention**: `follow($id, $id)` returns `false` without touching the DB.
- **Idempotent follow**: `INSERT OR IGNORE` / `INSERT IGNORE` — duplicate follow is a no-op returning `false`.
- **Directional**: A→B does not imply B→A. `isFollowing` reflects only the stated direction.
- **`isMutual` symmetry**: `isMutual(A, B) === isMutual(B, A)`.
- **No framework dependency**: Constructor accepts `?PDO`, falls back to `PdoConnection::getInstance()`.

## Test patterns

```php
$db = new PDO('sqlite::memory:');
$db->exec('CREATE TABLE follow_relations (
    follower_id VARCHAR(255) NOT NULL,
    followee_id VARCHAR(255) NOT NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (follower_id, followee_id)
)');
$fr = new FollowRelation($db);
```
