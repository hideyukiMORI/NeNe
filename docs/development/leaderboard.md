# Leaderboard

`Nene\Kit\Leaderboard` provides score submission with best-score retention, ranked listing, and personal rank lookup.

## Schema

```sql
CREATE TABLE leaderboard_scores (
    id             INTEGER      PRIMARY KEY AUTOINCREMENT,
    leaderboard_id VARCHAR(255) NOT NULL,
    user_id        VARCHAR(255) NOT NULL,
    score          INTEGER      NOT NULL,
    submitted_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (leaderboard_id, user_id)
);
CREATE INDEX idx_lb_scores ON leaderboard_scores (leaderboard_id, score DESC);
```

`UNIQUE (leaderboard_id, user_id)` enforces one row per user per leaderboard at the DB level.

## API

| Method | Returns | Description |
|--------|---------|-------------|
| `submit(leaderboardId, userId, score)` | `{is_best, score}` | Submit score. Kept only if it's a new personal best. |
| `rankings(leaderboardId, limit)` | `array[]` | Top entries. Tied scores share rank. Limit clamped to 1–100. |
| `rank(leaderboardId, userId)` | `{rank, score}\|null` | Personal rank and best score. Null if no score. |
| `remove(leaderboardId, userId)` | `bool` | Remove a user's score. |

## Basic usage

```php
use Nene\Kit\Leaderboard;

$lb = new Leaderboard();

// Submit a score
$result = $lb->submit('game:tetris', $userId, 42000);
if ($result['is_best']) {
    // New personal best!
}

// Get top 10
$rankings = $lb->rankings('game:tetris', limit: 10);
foreach ($rankings as $entry) {
    echo "#{$entry['rank']} {$entry['user_id']}: {$entry['score']}";
}

// Personal rank
$rank = $lb->rank('game:tetris', $userId);
if ($rank !== null) {
    echo "Your rank: #{$rank['rank']} with score {$rank['score']}";
}
```

## Best-score retention

`submit()` checks the existing score first:
- No existing score → INSERT (first submission)
- New score > current best → UPDATE (personal best achieved)
- New score ≤ current best → no change, `is_best: false`

## Tied scores

Users with identical scores share the same rank. The `rankings()` response uses dense-like ranking (the rank after two tied 1st-place entries is 3, not 2).

## Leaderboard ID convention

Use a namespaced string to support multiple leaderboards in one table:

```php
$lb->submit('game:tetris:daily', $userId, 1000);
$lb->submit('game:tetris:alltime', $userId, 1000);
$lb->submit('quiz:geography:weekly', $userId, 88);
```

## Score type

Scores must be integers. Reject floats at the input layer:

```php
$score = $requestBody['score'] ?? null;
if (!is_int($score)) {
    http_response_code(422);
    echo json_encode(['error' => 'VALIDATION-FAILED']);
    exit;
}
```

## Limit clamping

`rankings()` clamps `$limit` to 1–100 automatically. Passing `limit: 99999` does not cause a full table scan — the query is always bounded.
