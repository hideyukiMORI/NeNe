# Offset Pagination

Offset pagination lets callers navigate results by page number. It is the natural fit for admin tables, search results, and sitemap generation where the caller needs to jump to an arbitrary page.

Compare with **cursor pagination** (`CursorPage`, FT25), which is better for high-volume feeds and real-time streams where stable ordering and performance under deep pagination matter.

## When to Use Which

| Concern | Offset | Cursor |
| --- | --- | --- |
| Admin tables / search UIs | Yes | No |
| Page-number links (`?page=3`) | Yes | No |
| Deep pagination performance | Poor | Good |
| Real-time feeds (new rows appear) | Breaks consistency | Stable |
| Stable ordering required | Only if ORDER BY is stable | Yes |
| Simple to implement | Yes | More boilerplate |

**Rule of thumb**: if the UI shows numbered page links, use offset. If the UI shows "load more" or an infinite scroll, use cursor.

## OffsetPage API

`Nene\Xion\OffsetPage` is an immutable value object that holds one page of results and its navigation metadata.

### Constructor

```php
new OffsetPage(
    array $items,    // Items for the current page.
    int   $total,    // Total items across all pages.
    int   $page,     // Current page number (1-based).
    int   $perPage,  // Items per page.
)
```

### Properties (all `readonly`)

| Property | Type | Description |
| --- | --- | --- |
| `$items` | `array` | Items for the current page. |
| `$total` | `int` | Total items across all pages. |
| `$page` | `int` | Current page (1-based). |
| `$perPage` | `int` | Items per page. |
| `$totalPages` | `int` | Computed: `ceil($total / $perPage)`. |
| `$hasPrev` | `bool` | `true` when `$page > 1`. |
| `$hasNext` | `bool` | `true` when `$page < $totalPages`. |

### `toArray()`

Returns a structure suitable for `json_encode`:

```php
[
    'items' => [...],
    'pagination' => [
        'page'        => 2,
        'per_page'    => 20,
        'total'       => 55,
        'total_pages' => 3,
        'has_prev'    => true,
        'has_next'    => true,
    ],
]
```

## PaginationHelper API

`Nene\Func\PaginationHelper` provides pure static math helpers. No database calls, no state.

### `offset(int $page, int $perPage): int`

Returns the SQL `OFFSET` value for a given page (never negative).

```php
PaginationHelper::offset(1, 20); // 0
PaginationHelper::offset(2, 20); // 20
PaginationHelper::offset(3, 20); // 40
```

### `totalPages(int $total, int $perPage): int`

Returns `ceil($total / $perPage)`. Returns `0` when either argument is `<= 0`.

```php
PaginationHelper::totalPages(100, 10); // 10
PaginationHelper::totalPages(101, 10); // 11
PaginationHelper::totalPages(0, 10);   // 0
```

### `clamp(int $page, int $total, int $perPage): int`

Clamps a page number to `[1, totalPages]`. Returns `1` if there are no pages.

```php
PaginationHelper::clamp(0, 100, 10);  // 1
PaginationHelper::clamp(99, 100, 10); // 10
PaginationHelper::clamp(3, 100, 10);  // 3
```

### `window(int $currentPage, int $totalPages, int $window = 2): list<int>`

Generates a list of page numbers for a paginator UI. Pages outside the window are replaced with `0` (render as `…`).

```php
PaginationHelper::window(5, 10, 2);
// [1, 0, 3, 4, 5, 6, 7, 0, 10]

PaginationHelper::window(1, 10, 2);
// [1, 2, 3, 0, 10]

PaginationHelper::window(10, 10, 2);
// [1, 0, 8, 9, 10]
```

## SQL Pattern

Use `LIMIT` and `OFFSET` in the mapper:

```php
public function paginateOffset(int $page = 1, int $perPage = 20): OffsetPage
{
    $page    = max(1, $page);
    $perPage = max(1, min(100, $perPage));
    $total   = $this->countAll();
    $offset  = PaginationHelper::offset($page, $perPage);

    $items = $this->db->query(
        "SELECT * FROM {$this->TABLE} ORDER BY id DESC LIMIT {$perPage} OFFSET {$offset}"
    )->fetchAll(PDO::FETCH_ASSOC);

    return new OffsetPage($items, $total, $page, $perPage);
}
```

Always include an `ORDER BY` clause. Without it, row order is undefined and pagination will return inconsistent results.

## Controller Example

```php
public function index(): HttpResponse
{
    $page    = (int) ($this->request->query('page') ?? 1);
    $perPage = (int) ($this->request->query('per_page') ?? 20);

    $result = $this->todoMapper->paginateOffset(
        PaginationHelper::clamp($page, $this->todoMapper->countAll(), $perPage),
        $perPage,
    );

    return JsonResponder::responseArray($result->toArray());
}
```

## Page Window UI Rendering

In a Smarty template or JSON payload, iterate the `window()` result and render `0` as an ellipsis:

```php
$pages = PaginationHelper::window($currentPage, $totalPages, 2);
// PHP example — adapt for Smarty or front-end template
foreach ($pages as $p) {
    if ($p === 0) {
        echo '<span class="ellipsis">…</span>';
    } else {
        $class = $p === $currentPage ? 'current' : '';
        echo "<a href=\"?page={$p}\" class=\"{$class}\">{$p}</a>";
    }
}
```

## OpenAPI Schema for the Pagination Envelope

```yaml
components:
  schemas:
    PaginationMeta:
      type: object
      required: [page, per_page, total, total_pages, has_prev, has_next]
      properties:
        page:
          type: integer
          minimum: 1
        per_page:
          type: integer
          minimum: 1
        total:
          type: integer
          minimum: 0
        total_pages:
          type: integer
          minimum: 0
        has_prev:
          type: boolean
        has_next:
          type: boolean

    TodoListResponse:
      type: object
      required: [items, pagination]
      properties:
        items:
          type: array
          items:
            $ref: '#/components/schemas/Todo'
        pagination:
          $ref: '#/components/schemas/PaginationMeta'
```

## Related

- **CursorPage** (FT25, `Nene\Xion\CursorPage`) — cursor-based pagination for feeds and real-time streams.
- **PaginationHelper** (`Nene\Func\PaginationHelper`) — the math utilities shown above.
- **DataMapperBase** (`Nene\Xion\DataMapperBase`) — base class for mappers where `paginateOffset` is typically added.
