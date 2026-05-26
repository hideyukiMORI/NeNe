# SQL Injection Defense

NeNe's database layer uses PDO prepared statements for all value binding. This makes the most common injection vectors impossible by construction. However, one area requires extra care: **`ORDER BY` clauses cannot be parameterized**.

## What is safe by default

PDO prepared statements are the only supported way to bind user-supplied values:

```php
$stmt = $this->DB->prepare('
    SELECT * FROM articles WHERE user_id = :user_id AND title LIKE :q
');
$stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
$stmt->bindValue(':q',       '%' . $q . '%', PDO::PARAM_STR);
```

The driver treats bound values as data, not SQL structure. Classic injection payloads (`' OR '1'='1`, `'; DROP TABLE --`) are stored or searched for as literal strings.

This applies to: `WHERE`, `HAVING`, `INSERT` values, `UPDATE` values, `LIMIT`/`OFFSET` (when bound as int).

## LIKE injection

LIKE wildcards in the bound value itself (`%`, `_`) are NOT escaped by PDO — they are passed through as SQL wildcards. A value of `%` matches every row.

If user-supplied `%` and `_` should be treated as literals, escape them before binding:

```php
$raw   = (string)($this->REQUEST_JSON['q'] ?? '');
$safe  = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $raw);
$stmt->bindValue(':q', '%' . $safe . '%', PDO::PARAM_STR);
```

For most search boxes, `%` matching everything is merely incorrect, not a security issue. Decide whether to escape based on the consequence.

## ORDER BY — the mandatory whitelist

**SQL column names and sort directions cannot be parameterized.** This query does not work:

```php
// BROKEN — PDO binds :sort as a quoted string value, not a column name
$stmt = $this->DB->prepare('SELECT * FROM articles ORDER BY :sort ASC');
$stmt->bindValue(':sort', 'title');
// Result: ORDER BY 'title' ASC — no ordering, or error, depending on the driver
```

If you interpolate user input directly into `ORDER BY`, you create a SQL injection vector:

```php
// DANGEROUS — do not do this
$sort = $this->REQUEST_JSON['sort'] ?? 'id';
$stmt = $this->DB->prepare("SELECT * FROM articles ORDER BY {$sort} DESC");
```

An attacker can inject: `(SELECT SLEEP(5))`, `CASE WHEN 1=1 THEN title ELSE id END`, etc.

### Whitelist pattern

Validate the sort column against a strict allowlist before interpolation:

```php
class ArticleMapper extends DataMapperBase
{
    /** @var array<string, string> Allowed sort columns (input => SQL column). */
    private const SORT_COLUMNS = [
        'id'         => 'id',
        'title'      => 'title',
        'created_at' => 'created_at',
    ];

    /** @var array<string, string> Allowed sort directions. */
    private const SORT_DIRS = [
        'asc'  => 'ASC',
        'desc' => 'DESC',
    ];

    public function findPublishedRows(string $sortInput = 'id', string $dirInput = 'asc'): array
    {
        $col = self::SORT_COLUMNS[$sortInput] ?? 'id';          // unknown → safe default
        $dir = self::SORT_DIRS[strtolower($dirInput)] ?? 'ASC'; // unknown → safe default

        $stmt = $this->DB->prepare('
            SELECT id, title, created_at
            FROM ' . static::TARGET_TABLE . '
            WHERE is_deleted = 0
            ORDER BY ' . $col . ' ' . $dir . '
        ');

        return $this->execute($stmt)->fetchAll(PDO::FETCH_ASSOC);
    }
}
```

The controller reads `sort` and `order` from query params, validates format, and passes them to the mapper:

```php
public function indexGetRest(): array
{
    $sort  = (string)($this->REQUEST_JSON['sort']  ?? 'id');
    $order = (string)($this->REQUEST_JSON['order'] ?? 'asc');

    $mapper = new Database\ArticleMapper();
    return $this->API_RESPONSE->success([
        'articles' => $mapper->findPublishedRows($sort, $order),
    ]);
}
```

Unknown sort values fall back to the default silently, or you can return `SORT-FIELD-INVALID`:

```php
if (!array_key_exists($sort, ArticleMapper::SORT_COLUMNS)) {
    return $this->API_RESPONSE->failure('SORT-FIELD-INVALID');
}
```

### Sort direction injection is easy to neutralize

Even without a whitelist, direction injection is neutralized by normalization:

```php
// Any direction string that is not 'desc' becomes 'ASC' — injection impossible
$dir = strtolower($dirInput) === 'desc' ? 'DESC' : 'ASC';
```

Use this pattern for direction; use the whitelist pattern for column names.

## Path parameter coercion

URL path parameters (the `id` in `/article/item/id_42`) must always be cast to integers before use in SQL:

```php
$id = $this->request->getParam('id');
if ($id === null || !ctype_digit((string)$id)) {
    return $this->API_RESPONSE->failure('ARTICLE-ID-REQUIRED');
}
// Now safe to interpolate as integer
$stmt->bindValue(':id', (int)$id, PDO::PARAM_INT);
```

`ctype_digit()` rejects strings that `(int)` would silently truncate to 0 (e.g., `42abc` → `42`).

## Summary

| Source | Safe | How |
|---|---|---|
| `WHERE` value | ✅ | PDO bind |
| `INSERT` value | ✅ | PDO bind |
| `LIKE` pattern | ⚠️ | PDO bind; wildcards pass through — escape `%` / `_` if needed |
| `ORDER BY` column | ❌ | Must whitelist |
| `ORDER BY` direction | ✅ with care | Normalize: only `'ASC'` or `'DESC'` |
| Path `id` param | ✅ with care | `ctype_digit()` + `(int)` cast |

## Related

- `docs/development/idor-prevention.md` — object-level authorization
- `docs/tutorials/building-a-service.md` — mapper patterns
