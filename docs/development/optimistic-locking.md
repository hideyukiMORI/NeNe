# Optimistic Locking (ETag / If-Match)

When two users edit the same resource concurrently, the second write silently overwrites the first. This is the **lost-update problem**. Optimistic locking prevents it using HTTP conditional write semantics (ETag + If-Match).

NeNe has no built-in `If-Match` helper. This guide shows how to implement it in a mapper and controller.

## Framework helper: `OptimisticLock`

`Nene\Kit\OptimisticLock` provides the HTTP-layer primitives so controllers
stay concise:

| Method | Description |
|--------|-------------|
| `OptimisticLock::requireVersion()` | Read If-Match header → int; throws 428 if absent |
| `OptimisticLock::sendETag(int $v)` | Emit `ETag: "vN"` response header |
| `OptimisticLock::conflict()` | Throw 412 Precondition Failed (never returns) |
| `OptimisticLock::parseIfMatch(?string)` | Parse header string → int\|null (no side effects) |
| `OptimisticLock::etagFor(int $v)` | Return `"vN"` string without emitting a header |

### Minimal controller pattern

```php
// GET /docs/{id}
public function itemGetRest(): array
{
    $doc = $this->mapper->findById($this->getDocId());
    if ($doc === false) { /* ... 404 ... */ }
    OptimisticLock::sendETag($doc->version);
    return $this->API_RESPONSE->success(['doc' => $doc]);
}

// PUT /docs/{id}
public function itemPutRest(): array
{
    $version = OptimisticLock::requireVersion();   // 428 if absent
    $updated = $this->mapper->updateIfVersion(
        $this->getDocId(), $this->body(), $version
    );
    if ($updated === null) {
        OptimisticLock::conflict();                // 412 if stale
    }
    OptimisticLock::sendETag($updated->version);
    return $this->API_RESPONSE->success(['doc' => $updated]);
}
```

## How it works

1. **GET** returns the resource with an `ETag` header: `ETag: "v3"` (based on a `version` column).
2. **PUT/DELETE** client sends `If-Match: "v3"`.
3. Server checks: if the stored version matches, apply the write and increment `version`. If not, return `412 Precondition Failed`.
4. If `If-Match` is absent, return `428 Precondition Required`.

The client retries on 412 by fetching the latest version first.

## Schema

Add a `version` column to any resource that needs optimistic locking:

```php
// class/xion/SchemaDefinition.php
'documents' => [
    'columns' => [
        'id'         => ['type' => 'pk-bigint'],
        'created_at' => ['type' => 'datetime-now'],
        'updated_at' => ['type' => 'datetime-touch'],
        'title'      => ['type' => 'varchar:255'],
        'body'       => ['type' => 'text'],
        'version'    => ['type' => 'bigint'],         // optimistic lock counter
    ],
],
```

Seed the `version` to `1` on insert. Increment on every successful update.

## Mapper

```php
public function create(string $title, string $body): array
{
    $stmt = $this->DB->prepare('
        INSERT INTO ' . static::TARGET_TABLE . ' (title, body, version)
        VALUES (:title, :body, 1)
    ');
    $stmt->bindValue(':title', $title, PDO::PARAM_STR);
    $stmt->bindValue(':body',  $body,  PDO::PARAM_STR);
    $this->execute($stmt);
    return $this->findRowById((int)$this->DB->lastInsertId());
}

/**
 * Conditional update — only applies when stored version matches.
 *
 * @return array<string,mixed>|null Updated row, or null if version mismatch / not found.
 */
public function updateIfVersion(int $id, string $title, string $body, int $expectedVersion): ?array
{
    $stmt = $this->DB->prepare('
        UPDATE ' . static::TARGET_TABLE . '
        SET title = :title, body = :body, version = version + 1
        WHERE id = :id AND version = :version
    ');
    $stmt->bindValue(':title',   $title,           PDO::PARAM_STR);
    $stmt->bindValue(':body',    $body,             PDO::PARAM_STR);
    $stmt->bindValue(':id',      $id,               PDO::PARAM_INT);
    $stmt->bindValue(':version', $expectedVersion,  PDO::PARAM_INT);
    $this->execute($stmt);
    return $stmt->rowCount() > 0 ? $this->findRowById($id) : null;
}
```

When `rowCount() === 0`, it means either the document does not exist or the version has advanced — the response is 412 either way.

## Controller

```php
public function itemGetRest(): array
{
    $id  = $this->getDocumentId();
    $doc = (new Database\DocumentMapper())->findRowById($id);
    if ($doc === null) {
        return $this->API_RESPONSE->failure('DOCUMENT-NOT-FOUND');
    }

    // Emit ETag so the client knows the current version
    header('ETag: "v' . (int)$doc['version'] . '"');

    return $this->API_RESPONSE->success(['document' => $this->normalizeRow($doc)]);
}

public function itemPutRest(): array
{
    $id  = $this->getDocumentId();
    if ($id === null) {
        return $this->API_RESPONSE->failure('DOCUMENT-ID-REQUIRED');
    }

    // 428 Precondition Required — If-Match header is mandatory for conditional writes
    $ifMatch = $_SERVER['HTTP_IF_MATCH'] ?? '';
    if ($ifMatch === '') {
        return $this->API_RESPONSE->failure('PRECONDITION-REQUIRED');
    }

    // Parse ETag: "v3" → 3
    if (!preg_match('/^"v(\d+)"$/', $ifMatch, $m) && $ifMatch !== '*') {
        return $this->API_RESPONSE->failure('PRECONDITION-INVALID');
    }

    $title = trim((string)($this->REQUEST_JSON['title'] ?? ''));
    $body  = trim((string)($this->REQUEST_JSON['body']  ?? ''));

    $mapper = new Database\DocumentMapper();

    if ($ifMatch === '*') {
        // Wildcard — "match if resource exists"; fetch current version
        $current = $mapper->findRowById($id);
        if ($current === null) {
            return $this->API_RESPONSE->failure('DOCUMENT-NOT-FOUND');
        }
        $expectedVersion = (int)$current['version'];
    } else {
        $expectedVersion = (int)$m[1];
    }

    $updated = $mapper->updateIfVersion($id, $title, $body, $expectedVersion);

    if ($updated === null) {
        // Could be not-found OR stale version — return 412 (cannot distinguish without extra query)
        return $this->API_RESPONSE->failure('PRECONDITION-FAILED');
    }

    header('ETag: "v' . (int)$updated['version'] . '"');
    return $this->API_RESPONSE->success(['document' => $this->normalizeRow($updated)]);
}
```

## Error codes

```php
// config/error_codes.php
'PRECONDITION-REQUIRED'  => ['message' => 'If-Match header is required.',              'httpStatus' => 428],
'PRECONDITION-INVALID'   => ['message' => 'If-Match header value is not a valid ETag.', 'httpStatus' => 400],
'PRECONDITION-FAILED'    => ['message' => 'Resource has been modified since last read.', 'httpStatus' => 412],
```

## Caveats

- Optimistic locking is appropriate for low-to-medium concurrency scenarios. Under very high write contention, clients spend most time retrying — consider pessimistic locking (SELECT ... FOR UPDATE) in those cases.
- If you cannot distinguish "not found" from "version mismatch" (because the update affected 0 rows for both reasons), returning 412 for both is acceptable. A subsequent GET will reveal whether the document exists.
- `$_SERVER['HTTP_IF_MATCH']` works in NeNe's Apache-based Docker setup. In other environments, confirm the header is forwarded (some load balancers strip unknown headers).

## When to use it

Use optimistic locking when:
- Multiple users can edit the same resource (documents, shared notes, configs).
- Writes are infrequent relative to reads.
- A lost-update causes user-visible data loss.

Skip it for single-owner resources (the owner is the only writer — no concurrency risk).

## Related

- `docs/development/idor-prevention.md` — object-level authorization
- `docs/tutorials/building-a-service.md` — controller and mapper patterns
