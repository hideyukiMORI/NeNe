# Soft Delete (Logical Deletion) in NeNe

NeNe supports soft-delete via `DataMapperBase`. When enabled, `DELETE` operations
set a `deleted_at` timestamp instead of removing the row. Deleted rows can be
restored, listed, or permanently purged.

## Enable soft delete on a mapper

Add `deleted_at DATETIME NULL DEFAULT NULL` to your table schema and set
`SOFT_DELETE = true` in the mapper:

```php
class NoteMapper extends DataMapperBase
{
    protected const MODEL_CLASS  = Note::class;
    protected const TARGET_TABLE = 'notes';
    protected const SOFT_DELETE  = true;
}
```

That's it. The base class handles the rest automatically.

## What changes automatically

| Method | SOFT_DELETE = false | SOFT_DELETE = true |
|--------|--------------------|--------------------|
| `find($id)` | all rows | active rows only |
| `findALL()` | all rows | active rows only |
| `findPage()` | all rows | active rows only |
| `countAll()` | all rows | active rows only |
| `delete($obj)` | physical DELETE | sets `deleted_at = NOW()` |

## New methods (only work when SOFT_DELETE = true)

| Method | Description |
|--------|-------------|
| `softDelete($obj)` | Explicitly soft-delete (same as `delete()` when SOFT_DELETE=true) |
| `restore(int $id)` | Clear `deleted_at` → bool |
| `findTrashed()` | List soft-deleted rows |
| `purge(int $id)` | Physical delete of one trashed row → bool |
| `purgeAll()` | Physical delete of all trashed rows → int |

## Schema example

```sql
CREATE TABLE notes (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    title      VARCHAR(255) NOT NULL,
    body       TEXT,
    deleted_at DATETIME NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

## Controller pattern

```php
// Soft delete
$note = $this->noteMapper->find($id);
if ($note === false) { /* 404 */ }
$this->noteMapper->delete($note);      // sets deleted_at

// Restore
$restored = $this->noteMapper->restore($id);
if (!$restored) { /* was not in trash — 404 */ }

// List trash
$stmt    = $this->noteMapper->findTrashed();
$trashed = $stmt->fetchAll();

// Purge one item
$purged = $this->noteMapper->purge($id);

// Purge all
$count = $this->noteMapper->purgeAll();
```

## Security note

Without soft delete, a bug or typo in a WHERE clause can silently expose
deleted data. The `SOFT_DELETE = true` default-exclude pattern ensures that
deleted rows never appear in standard `find()` / `findALL()` results, reducing
the blast radius of query mistakes (see NENE2 FT108 F-1).
