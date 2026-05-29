# Voting Booth

`Nene\Kit\VotingBooth` provides an upvote/downvote system with toggle semantics, score retrieval, and per-user vote state.

## Toggle semantics

| Previous vote | New vote | Result |
|---------------|----------|--------|
| none | up or down | INSERT (vote added) |
| up | up | DELETE (toggle — vote removed) |
| down | down | DELETE (toggle — vote removed) |
| up | down | UPDATE (direction switched) |
| down | up | UPDATE (direction switched) |

Clicking the same button twice removes the vote. Clicking the opposite button switches it.

## Schema

```sql
CREATE TABLE votes (
    id         INTEGER      PRIMARY KEY AUTOINCREMENT,
    target_id  VARCHAR(255) NOT NULL,
    user_id    VARCHAR(255) NOT NULL,
    direction  VARCHAR(4)   NOT NULL CHECK (direction IN ('up', 'down')),
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (target_id, user_id)
);
CREATE INDEX idx_votes_target ON votes (target_id);
```

`UNIQUE (target_id, user_id)` enforces one-vote-per-user at the DB level as a second safeguard.

## API

| Method | Returns | Description |
|--------|---------|-------------|
| `cast(targetId, userId, direction)` | `{vote, score}` | Cast, toggle, or switch. Returns current vote and net score. |
| `score(targetId)` | `{up, down, score}` | Vote counts and net score (`up - down`). |
| `userVote(targetId, userId)` | `string\|null` | Current vote direction (`'up'`, `'down'`, or `null`). |
| `validDirection(direction)` | `bool` | Static helper: check if string is `'up'` or `'down'`. |

## Basic usage

```php
use Nene\Kit\VotingBooth;

$booth = new VotingBooth();

// Validate input
$direction = $requestBody['direction'] ?? '';
if (!VotingBooth::validDirection($direction)) {
    http_response_code(422);
    echo json_encode(['error' => 'VALIDATION-FAILED']);
    exit;
}

// Cast a vote (cast also handles toggle and switch)
$result = $booth->cast('post:42', 'user:1', $direction);
// $result['vote']  → 'up', 'down', or null (removed)
// $result['score'] → net score (upvotes - downvotes)

// Current vote state (for rendering the active button in UI)
$currentVote = $booth->userVote('post:42', 'user:1');

// Score without voting
$score = $booth->score('post:42');
// ['up' => 5, 'down' => 2, 'score' => 3]
```

## Target ID convention

Use a namespaced string to allow multiple votable types in one table:

```php
$booth->cast('post:42', 'user:1', 'up');
$booth->cast('comment:17', 'user:1', 'up');
$booth->cast('answer:99', 'user:2', 'down');
```

## Security note

`cast()` does not enforce that `$userId` belongs to the authenticated user. Bind the user ID from the JWT claims or session to prevent impersonation:

```php
$claims = $jwt->require(); // from JwtCodec
$userId = $claims['sub'];
$booth->cast($targetId, $userId, $direction);
```
