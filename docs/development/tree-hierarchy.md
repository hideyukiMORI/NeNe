# Tree / Hierarchy — Adjacency List Pattern

This guide covers working with nested data (categories, menus, comment threads) in
NeNe using the adjacency-list model and the `TreeHelper` utility class.

---

## What is the adjacency list pattern?

Every row stores only its own `id` and its direct `parent_id`. Root nodes use
`NULL` (or `0`) for `parent_id`. The tree structure is implicit in the data.

```
id | parent_id | name
---|-----------|-------------
 1 |      NULL | Electronics
 2 |         1 | Laptops
 3 |         2 | Gaming
 4 |         1 | Tablets
 5 |      NULL | Clothing
 6 |         5 | Shirts
```

**When to use adjacency list:**
- The tree is shallow (< 5–6 levels in practice).
- Reads happen once per request — fetch everything, work in PHP.
- The schema must stay simple: one foreign-key column.
- You need fast single-node inserts / moves (only the `parent_id` column changes).

**Alternatives to consider:**
- **Nested sets** — fast subtree reads, slow writes.
- **Materialized path** — easy LIKE queries, moderate write cost.
- **Recursive CTEs** — database-native traversal, good for large trees.

---

## Typical SQL schema

```sql
CREATE TABLE categories (
    id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    parent_id INT UNSIGNED NULL DEFAULT NULL,
    name      VARCHAR(120)  NOT NULL,
    sort      SMALLINT      NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_parent (parent_id),
    CONSTRAINT fk_category_parent
        FOREIGN KEY (parent_id) REFERENCES categories (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
);
```

Root nodes use `parent_id = NULL`. A partial index on `parent_id` speeds up both
"fetch roots" queries and subtree queries.

---

## TreeHelper API reference

`Nene\Func\TreeHelper` — all methods are `static`.

| Method | Signature | Description |
|---|---|---|
| `build` | `build(array $nodes, ?int $rootId = null, string $idKey = 'id', string $parentKey = 'parent_id'): array` | Build nested tree; returns list of root nodes each with a `children` key. |
| `ancestors` | `ancestors(array $nodes, int $nodeId, ...): array` | Return root-first ancestor chain (excludes the target node). Suitable for breadcrumbs. |
| `descendants` | `descendants(array $nodes, int $nodeId, ...): array` | Return flat list of all nodes under the given node (any depth). |
| `depth` | `depth(array $nodes, int $nodeId, ...): int` | Return 0-based depth of a node. Returns -1 if not found. |
| `flatten` | `flatten(array $tree, int $depth = 0): array` | Flatten a nested tree back to a flat list with a `_depth` key added to each node. |

All methods accept optional `$idKey` and `$parentKey` parameters to support
non-standard column names.

---

## Fetching the full tree with one query

```php
// In a mapper / repository
$categories = $this->db->fetchAll('SELECT id, parent_id, name FROM categories ORDER BY sort, name');

// All TreeHelper operations are then pure PHP — no further DB calls.
```

Fetching everything once and working in PHP is appropriate for trees where the
entire set fits comfortably in memory. For category trees of up to ~1 000 nodes
this is the recommended approach.

---

## Build pattern — nested JSON for API responses

```php
use Nene\Func\TreeHelper;

$flat   = $categoryMapper->findAll();
$tree   = TreeHelper::build($flat);

// Returns: [
//   ['id'=>1, 'name'=>'Electronics', 'children'=>[
//     ['id'=>2, 'name'=>'Laptops', 'children'=>[
//       ['id'=>3, 'name'=>'Gaming', 'children'=>[]]
//     ]],
//     ['id'=>4, 'name'=>'Tablets', 'children'=>[]]
//   ]],
//   ...
// ]

header('Content-Type: application/json');
echo json_encode($tree);
```

---

## Flatten pattern — `<select>` menus with indentation

```php
use Nene\Func\TreeHelper;

$flat   = $categoryMapper->findAll();
$tree   = TreeHelper::build($flat);
$items  = TreeHelper::flatten($tree);

// $items is a depth-sorted flat list; each node has a `_depth` key.
// Render an indented <option> list:
foreach ($items as $item) {
    $indent = str_repeat('　', $item['_depth']);   // full-width space for visual indent
    echo "<option value=\"{$item['id']}\">{$indent}{$item['name']}</option>\n";
}
```

---

## Ancestors — breadcrumb navigation

```php
use Nene\Func\TreeHelper;

$flat       = $categoryMapper->findAll();
$crumbs     = TreeHelper::ancestors($flat, $currentCategoryId);
// $crumbs is ordered root-first (Electronics → Laptops)
// The current node is NOT included; append it yourself if needed.

foreach ($crumbs as $crumb) {
    echo '<a href="/category/' . $crumb['id'] . '">' . htmlspecialchars($crumb['name']) . '</a> › ';
}
echo htmlspecialchars($currentCategoryName);
```

---

## Descendants — bulk operations on a subtree

```php
use Nene\Func\TreeHelper;

$flat        = $categoryMapper->findAll();
$subtree     = TreeHelper::descendants($flat, $parentCategoryId);
$ids         = array_column($subtree, 'id');

// Soft-delete the entire subtree in one query:
if (!empty($ids)) {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $db->execute(
        "UPDATE categories SET deleted_at = NOW() WHERE id IN ($placeholders)",
        $ids,
    );
}
```

---

## Depth — indented display

```php
use Nene\Func\TreeHelper;

$flat  = $categoryMapper->findAll();
$depth = TreeHelper::depth($flat, $nodeId);

if ($depth === -1) {
    // node does not exist
}
// depth 0 = root, 1 = first child, 2 = grandchild, …
```

---

## Performance note

All `TreeHelper` methods iterate over the flat `$nodes` array in PHP.

| Method | Complexity |
|---|---|
| `build` | O(n²) — each recursion scans the full array |
| `ancestors` | O(n) — index built once, then pointer-following |
| `descendants` | O(n²) — recursive scan |
| `depth` | O(n) — index built once, then pointer-following |
| `flatten` | O(n) — single pass |

**O(n²) is acceptable for trees under ~1 000 nodes** — typical for category or
menu hierarchies. For larger datasets (forums with millions of threads, org charts
with tens of thousands of nodes) consider:

- A **recursive CTE** (`WITH RECURSIVE`) — single SQL query, scales to any size.
- A **nested set** model — fast reads for subtrees, expensive writes.
- Pre-computing and caching the tree (Redis, APCu) with a TTL that matches the
  update frequency.
