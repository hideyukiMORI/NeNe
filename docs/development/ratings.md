# Ratings and Aggregations

How to implement a star-rating system with upsert semantics: one rating per (item, rater) pair, live aggregated summary (average, count, distribution), and per-rater CRUD.

## The upsert pattern

Ratings are naturally "create or update" — a user may re-rate an item any number of times, and each re-rate replaces the previous score. The cleanest way to implement this is:

1. Attempt `INSERT`.
2. On UNIQUE constraint violation, `UPDATE` instead.

This is more reliable than a SELECT-then-branch because it avoids a TOCTOU race where two concurrent requests could both pass the SELECT check and both INSERT.

## Schema

```php
// class/xion/SchemaDefinition.php

'ratings' => [
    'columns' => [
        'id'         => ['type' => 'pk-bigint'],
        'created_at' => ['type' => 'datetime-now'],
        'updated_at' => ['type' => 'datetime-touch'],
        'item_id'    => ['type' => 'bigint'],
        'rater_id'   => ['type' => 'bigint'],
        'score'      => ['type' => 'bigint'],           // 1–5 (validate in application)
        'review'     => ['type' => 'text'],
    ],
    'unique' => [
        'ratings_item_rater_unique' => ['item_id', 'rater_id'],
    ],
    'indexes' => [
        'ratings_item_id_index' => ['item_id'],
    ],
],
```

> **CHECK constraint:** MySQL supports `CHECK(score BETWEEN 1 AND 5)` in CREATE TABLE. Validate in application code first (return 422) — the DB constraint is a safety net.

## Mapper: upsert

```php
// class/db/RatingMapper.php

class RatingMapper extends \Nene\Xion\DataMapperBase
{
    const TARGET_TABLE = 'ratings';
    const KEY_SID      = 'id';

    public function upsert(int $itemId, int $raterId, int $score, string $review): array
    {
        try {
            $stmt = $this->DB->prepare('
                INSERT INTO ' . static::TARGET_TABLE . ' (item_id, rater_id, score, review)
                VALUES (:item, :rater, :score, :review)
            ');
            $stmt->bindValue(':item',   $itemId,  PDO::PARAM_INT);
            $stmt->bindValue(':rater',  $raterId, PDO::PARAM_INT);
            $stmt->bindValue(':score',  $score,   PDO::PARAM_INT);
            $stmt->bindValue(':review', $review,  PDO::PARAM_STR);
            $this->execute($stmt);
            return $this->findRowById((int)$this->DB->lastInsertId());
        } catch (\PDOException $e) {
            if (str_starts_with((string)$e->getCode(), '23')) { // UNIQUE violation
                $upd = $this->DB->prepare('
                    UPDATE ' . static::TARGET_TABLE . '
                    SET score = :score, review = :review
                    WHERE item_id = :item AND rater_id = :rater
                ');
                $upd->bindValue(':score',  $score,   PDO::PARAM_INT);
                $upd->bindValue(':review', $review,  PDO::PARAM_STR);
                $upd->bindValue(':item',   $itemId,  PDO::PARAM_INT);
                $upd->bindValue(':rater',  $raterId, PDO::PARAM_INT);
                $this->execute($upd);

                // Return the updated row
                $find = $this->DB->prepare('
                    SELECT * FROM ' . static::TARGET_TABLE . '
                    WHERE item_id = :item AND rater_id = :rater LIMIT 1
                ');
                $find->bindValue(':item',  $itemId,  PDO::PARAM_INT);
                $find->bindValue(':rater', $raterId, PDO::PARAM_INT);
                return $this->execute($find)->fetch(PDO::FETCH_ASSOC);
            }
            throw $e;
        }
    }

    /**
     * @return array{average: float|null, count: int, distribution: array<int, int>}
     */
    public function summary(int $itemId): array
    {
        $stmt = $this->DB->prepare('
            SELECT AVG(score) AS avg_score, COUNT(*) AS cnt
            FROM ' . static::TARGET_TABLE . '
            WHERE item_id = :item
        ');
        $stmt->bindValue(':item', $itemId, PDO::PARAM_INT);
        $row = $this->execute($stmt)->fetch(PDO::FETCH_ASSOC);

        $count   = (int)($row['cnt'] ?? 0);
        $average = $count > 0 ? round((float)$row['avg_score'], 2) : null;

        // Score distribution (1–5)
        $dist = array_fill(1, 5, 0);
        if ($count > 0) {
            $dStmt = $this->DB->prepare('
                SELECT score, COUNT(*) AS n
                FROM ' . static::TARGET_TABLE . '
                WHERE item_id = :item
                GROUP BY score
            ');
            $dStmt->bindValue(':item', $itemId, PDO::PARAM_INT);
            foreach ($this->execute($dStmt)->fetchAll(PDO::FETCH_ASSOC) as $d) {
                $dist[(int)$d['score']] = (int)$d['n'];
            }
        }

        return [
            'average'      => $average,
            'count'        => $count,
            'distribution' => $dist,
        ];
    }

    public function findByRater(int $itemId, int $raterId): ?array
    {
        $stmt = $this->DB->prepare('
            SELECT * FROM ' . static::TARGET_TABLE . '
            WHERE item_id = :item AND rater_id = :rater LIMIT 1
        ');
        $stmt->bindValue(':item',  $itemId,  PDO::PARAM_INT);
        $stmt->bindValue(':rater', $raterId, PDO::PARAM_INT);
        $row = $this->execute($stmt)->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function deleteByRater(int $itemId, int $raterId): bool
    {
        $stmt = $this->DB->prepare('
            DELETE FROM ' . static::TARGET_TABLE . '
            WHERE item_id = :item AND rater_id = :rater
        ');
        $stmt->bindValue(':item',  $itemId,  PDO::PARAM_INT);
        $stmt->bindValue(':rater', $raterId, PDO::PARAM_INT);
        $this->execute($stmt);
        return $stmt->rowCount() > 0;
    }
}
```

## Controller: upsert endpoint

```php
// PUT /rating/item/id_{itemId}/rater/id_{raterId}
public function upsertPutRest(int $itemId, int $raterId): array
{
    $score  = (int)($this->REQUEST_JSON['score']  ?? 0);
    $review = trim((string)($this->REQUEST_JSON['review'] ?? ''));

    if ($score < 1 || $score > 5) {
        return $this->API_RESPONSE->failure('RATING-SCORE-INVALID');
    }

    $rating = (new Database\RatingMapper())->upsert($itemId, $raterId, $score, $review);
    return $this->API_RESPONSE->success(['rating' => $this->normalizeRow($rating)]);
}

// GET /rating/item/id_{itemId}/summary
public function summaryGetRest(int $itemId): array
{
    $summary = (new Database\RatingMapper())->summary($itemId);
    return $this->API_RESPONSE->success($summary);
}
```

## Response: summary

```json
{
    "status": "success",
    "Data": {
        "average": 4.25,
        "count": 8,
        "distribution": { "1": 0, "2": 1, "3": 0, "4": 3, "5": 4 }
    }
}
```

`average` is `null` when no ratings exist (returns `null` instead of 0 to distinguish "no data" from "zero average").

## Error codes

```php
// config/error_codes.php
'RATING-SCORE-INVALID' => ['message' => 'Score must be between 1 and 5.', 'httpStatus' => 422],
'RATING-NOT-FOUND'     => ['message' => 'Rating not found.',              'httpStatus' => 404],
```

## MySQL upsert alternative

MySQL supports `ON DUPLICATE KEY UPDATE` which avoids the try-catch pattern:

```sql
INSERT INTO ratings (item_id, rater_id, score, review)
VALUES (?, ?, ?, ?)
ON DUPLICATE KEY UPDATE score = VALUES(score), review = VALUES(review)
```

The try-catch approach works for both MySQL and SQLite and is preferred when targeting multiple databases.

## Related

- `docs/development/booking-systems.md` — UNIQUE constraint as the primary guard
- `docs/development/ledger-systems.md` — idempotency key pattern
- `docs/development/reactions.md` — toggle pattern (add/remove, same UNIQUE-constraint base)
