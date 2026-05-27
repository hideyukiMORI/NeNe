# File Metadata

`Nene\Xion\FileMetadata` — file storage metadata management with soft delete.

Records file metadata (path, MIME type, size, owner, storage backend) in the database. Does **not** manage the physical file — upload and deletion of the actual bytes is the application's responsibility.

## Schema

```sql
CREATE TABLE file_metadata (
    id           INTEGER      PRIMARY KEY AUTOINCREMENT,
    owner_id     VARCHAR(255) NOT NULL,
    path         VARCHAR(1024) NOT NULL,
    filename     VARCHAR(255) NOT NULL,
    mime_type    VARCHAR(127) NOT NULL,
    size_bytes   INTEGER      NOT NULL DEFAULT 0,
    storage      VARCHAR(64)  NOT NULL DEFAULT 'local',
    deleted_at   DATETIME     DEFAULT NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_file_metadata_owner ON file_metadata (owner_id, deleted_at);
```

## API

| Method | Description |
| --- | --- |
| `register(string $ownerId, string $path, string $filename, string $mimeType, int $sizeBytes = 0, string $storage = 'local'): int` | Register file metadata. Returns ID. |
| `find(int $id): ?array` | Get file by ID (including soft-deleted). Returns null if not found. |
| `findByOwner(string $ownerId, ?string $mimeType = null): array` | Active (non-deleted) files for owner, optionally filtered by MIME. |
| `delete(int $id, string $ownerId): bool` | Soft-delete. Owner-enforced. Returns true if deleted. |

## Usage

```php
$fm = new FileMetadata($pdo);

// After upload — register metadata
$id = $fm->register(
    'user:1',
    '/uploads/2026/05/abc123.jpg',    // storage path
    'photo.jpg',                       // original filename
    'image/jpeg',
    204800,                            // 200 KB
    'local',                           // or 's3', 'gcs', etc.
);

// Find by ID
$file = $fm->find($id);
// ['id' => 1, 'owner_id' => 'user:1', 'path' => '/uploads/...', 'mime_type' => 'image/jpeg', ...]

// List active files
$fm->findByOwner('user:1');               // all types
$fm->findByOwner('user:1', 'image/');     // images only (LIKE 'image/%')
$fm->findByOwner('user:1', 'application/pdf');  // exact MIME

// Soft delete (row retained, deleted_at set)
$fm->delete($id, 'user:1');   // true
$fm->delete($id, 'user:2');   // false — wrong owner
$fm->delete($id, 'user:1');   // false — already deleted
```

## Soft delete

`delete()` sets `deleted_at` but keeps the row. `findByOwner()` excludes deleted files. `find()` returns deleted files (for admin/audit use). The physical file deletion is the application's responsibility.

## MIME type filter

`findByOwner($userId, 'image/')` matches any MIME starting with `image/` (JPEG, PNG, WebP, etc.) using `LIKE 'image/%'`. Pass a full MIME type for exact match:

```php
$fm->findByOwner('user:1', 'image/jpeg');        // exact
$fm->findByOwner('user:1', 'image/');            // all images
$fm->findByOwner('user:1', 'application/pdf');   // exact
```

## Key design points

- **Metadata only**: no file I/O — decoupled from storage layer.
- **`storage` field**: identifies the backend (`'local'`, `'s3'`, `'gcs'`); app uses it to route deletion.
- **Soft delete**: owner-enforced; `deleted_at IS NULL` filter in `findByOwner()`.
- **PDO injection**: `__construct(private readonly ?PDO $db = null)`.

## Test patterns

```php
$db = new PDO('sqlite::memory:');
$db->exec('CREATE TABLE file_metadata (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    owner_id VARCHAR(255) NOT NULL,
    path VARCHAR(1024) NOT NULL,
    filename VARCHAR(255) NOT NULL,
    mime_type VARCHAR(127) NOT NULL,
    size_bytes INTEGER NOT NULL DEFAULT 0,
    storage VARCHAR(64) NOT NULL DEFAULT \'local\',
    deleted_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
)');
$fm = new FileMetadata($db);
```
