# Cursor-based Pagination in NeNe

NeNe provides `DataMapperBase::findPage()` for keyset (cursor-based) pagination.
Cursor pagination is preferred over `OFFSET` for any list that may exceed a few
hundred rows — deep offsets are slow and unstable under concurrent inserts.

## How it works

`findPage()` encodes a `(created_at, id)` pair as an opaque base64url token.
The client passes the token back as `?after=<cursor>` to fetch the next page.

```php
// Controller
$cursor = $this->REQUEST->get('after');   // ?after=<token> or null
$limit  = (int)($this->REQUEST->get('limit') ?? 20);

$page = $this->mapper->findPage($cursor !== '' ? $cursor : null, $limit);

return $this->API_RESPONSE->success($page->toArray());
```

Response shape:

```json
{
  "Result": true,
  "Data": {
    "status": "success",
    "errorCode": "",
    "items": [...],
    "has_more": true,
    "next_cursor": "eyJjIjoiMjAyNi0wNS0yNyAxMjowMDowMCIsImkiOjQyfQ"
  }
}
```

## Requirements

- The table **must** have a `created_at` column (constant `DB_COLUMN_NAME_CREATED`).
- The primary key column is taken from `static::KEY_SID` (default: `id`).
- Records are returned **newest-first** (`created_at DESC, id DESC`).

## Limit clamping

`$limit` is clamped to `[1, 100]` automatically.

## Invalid cursor handling

`Cursor::decode()` returns `null` for any malformed token.
`findPage()` treats a `null` cursor as a first-page request (no filter applied).
Return a 400 to the client if you want to reject invalid cursors explicitly:

```php
use Nene\Xion\Cursor;

$raw = $this->REQUEST->get('after');
if ($raw !== null && $raw !== '' && Cursor::decode($raw) === null) {
    // 400 Bad Request — invalid cursor
    return $this->API_RESPONSE->failure('INVALID_CURSOR');
}
$page = $this->mapper->findPage($raw ?: null, $limit);
```

## OpenAPI snippet

```yaml
parameters:
  - name: after
    in: query
    required: false
    schema:
      type: string
    description: Opaque cursor token returned by the previous page's next_cursor.
  - name: limit
    in: query
    required: false
    schema:
      type: integer
      default: 20
      minimum: 1
      maximum: 100
responses:
  "200":
    content:
      application/json:
        schema:
          $ref: "#/components/schemas/CursorPageEnvelope"
```

```yaml
components:
  schemas:
    CursorPageEnvelope:
      type: object
      required: [Result, Data]
      properties:
        Result:
          type: boolean
        Data:
          type: object
          required: [status, errorCode, items, has_more, next_cursor]
          properties:
            status:
              type: string
              enum: [success]
            errorCode:
              type: string
            items:
              type: array
              items: {}
            has_more:
              type: boolean
            next_cursor:
              type: string
              nullable: true
```
