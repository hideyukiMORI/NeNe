# Full-Text Search in NeNe

NeNe supports two first-class search strategies and one fallback:

| Strategy | Best for | Schema change? |
|---|---|---|
| SQLite FTS5 | Large text fields, ranked relevance | Yes — virtual table |
| MySQL FULLTEXT | Medium text, InnoDB | Yes — FULLTEXT index |
| LIKE fallback | Short fields, simple substring | No |

---

## 1. SQLite FTS5

FTS5 is SQLite's built-in full-text search engine. It is the recommended approach for content-heavy search (posts, comments, articles) when running with the SQLite backend.

### 1.1 Schema

Create a content-backed virtual table that mirrors an existing `posts` table:

```sql
CREATE VIRTUAL TABLE posts_fts USING fts5(
    title, body, tags,
    content='posts', content_rowid='id'
);
```

The `content=` option tells FTS5 to read data from `posts` instead of storing its own copy; `content_rowid=` maps FTS5's internal rowid to `posts.id`.

### 1.2 Keeping the index in sync

Content-backed FTS5 tables do not update automatically. Create three triggers:

```sql
-- INSERT
CREATE TRIGGER posts_ai AFTER INSERT ON posts BEGIN
    INSERT INTO posts_fts(rowid, title, body, tags)
    VALUES (new.id, new.title, new.body, new.tags);
END;

-- DELETE
CREATE TRIGGER posts_ad AFTER DELETE ON posts BEGIN
    INSERT INTO posts_fts(posts_fts, rowid, title, body, tags)
    VALUES ('delete', old.id, old.title, old.body, old.tags);
END;

-- UPDATE (delete old, insert new)
CREATE TRIGGER posts_au AFTER UPDATE ON posts BEGIN
    INSERT INTO posts_fts(posts_fts, rowid, title, body, tags)
    VALUES ('delete', old.id, old.title, old.body, old.tags);
    INSERT INTO posts_fts(rowid, title, body, tags)
    VALUES (new.id, new.title, new.body, new.tags);
END;
```

### 1.3 MATCH query

```php
use Nene\Func\SearchQuery;

$safeQuery = SearchQuery::sanitizeFts($userInput);
if ($safeQuery === '') {
    // Return empty result set or 400, do not run MATCH with empty string
    return [];
}

$stmt = $this->DB->prepare(
    'SELECT p.* FROM posts p
     JOIN posts_fts f ON f.rowid = p.id
     WHERE posts_fts MATCH :q
     ORDER BY f.rank
     LIMIT :limit'
);
$stmt->bindValue(':q',     $safeQuery, PDO::PARAM_STR);
$stmt->bindValue(':limit', 20,         PDO::PARAM_INT);
$stmt->execute();
return $stmt->fetchAll(PDO::FETCH_ASSOC);
```

### 1.4 rank is negative — BM25 scoring

`f.rank` is a negative floating-point number. FTS5 uses **BM25** scoring; lower (more negative) values mean a better match. `ORDER BY f.rank` therefore returns the most relevant results first without needing `DESC`.

Example values: `-5.2`, `-3.8`, `-1.0`. A row with rank `-5.2` ranks above `-1.0`.

### 1.5 Handling invalid queries

An unmatched double quote or an otherwise malformed MATCH expression causes SQLite to raise a `\PDOException` with a message like `fts5: syntax error near "unclosed"`. Catch this to return a 400 rather than a 500:

```php
try {
    $stmt->execute();
} catch (\PDOException $e) {
    if (str_contains($e->getMessage(), 'fts5:')) {
        // Invalid FTS5 query syntax — treat as a bad request
        throw new \Nene\Xion\HttpException(400, 'SEARCH-INVALID-QUERY');
    }
    throw $e;
}
```

`SearchQuery::sanitizeFts()` strips double quotes before they reach PDO, so this catch is a last-resort safety net.

---

## 2. MySQL FULLTEXT

MySQL InnoDB supports `MATCH … AGAINST` full-text search. Add a FULLTEXT index to your table:

```sql
ALTER TABLE posts
    ADD FULLTEXT INDEX ft_posts_title_body (title, body);
```

Query example (natural language mode):

```sql
SELECT * FROM posts
WHERE MATCH(title, body) AGAINST(:q IN NATURAL LANGUAGE MODE)
ORDER BY MATCH(title, body) AGAINST(:q IN NATURAL LANGUAGE MODE) DESC
LIMIT 20;
```

MySQL FULLTEXT has a minimum word length (default 4 characters) and stopwords that can affect results. For short terms, fall back to LIKE.

---

## 3. LIKE fallback

For simple substring matching on short fields, no schema change is needed. Use `SearchQuery::likePattern()` to build a safe LIKE pattern and bind it via PDO.

```php
use Nene\Func\SearchQuery;

$pattern = SearchQuery::likePattern($userInput); // '%term%'

$stmt = $this->DB->prepare(
    "SELECT * FROM users WHERE name LIKE :pattern ESCAPE '\\\\' LIMIT :limit"
);
$stmt->bindValue(':pattern', $pattern,  PDO::PARAM_STR);
$stmt->bindValue(':limit',   50,        PDO::PARAM_INT);
$stmt->execute();
```

`escapeLike()` escapes `%`, `_`, and `\` with a backslash; the `ESCAPE '\\'` clause tells the database that `\` is the escape character.

### 3.1 Mode variants

```php
SearchQuery::likePattern('hello');              // '%hello%'  (contains)
SearchQuery::likePattern('hello', 'starts-with'); // 'hello%'
SearchQuery::likePattern('hello', 'ends-with');   // '%hello'
```

---

## 4. Input normalisation

Always normalise user input before searching to avoid surprises from null bytes or excess whitespace:

```php
$term = SearchQuery::normalize($rawInput); // collapse whitespace, remove null bytes, trim
if ($term === '') {
    return []; // empty query → empty result (or 400 depending on your API contract)
}
```

---

## 5. Choosing a strategy

```
Need ranked relevance on large text fields?
  └─ SQLite backend → FTS5
  └─ MySQL backend  → FULLTEXT

Short field, simple "does it contain X?"
  └─ LIKE fallback (no schema change)
```

---

## 6. Helpers reference

| Method | Description |
|---|---|
| `SearchQuery::escapeLike(string $term): string` | Escape `%`, `_`, `\` for SQL LIKE |
| `SearchQuery::likePattern(string $term, string $mode): string` | Build a complete LIKE pattern |
| `SearchQuery::sanitizeFts(string $term): string` | Strip characters that break FTS5 MATCH |
| `SearchQuery::normalize(string $input): string` | Remove null bytes, collapse whitespace, trim |
