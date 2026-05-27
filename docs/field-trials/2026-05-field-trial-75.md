# Field Trial 75 — File Storage Metadata

**Date**: 2026-05-27
**Branch**: `feat/ft75-file-metadata`
**Baseline**: post FT74 merge

## Goal

Establish a file storage metadata pattern for NeNe applications. Track uploaded file metadata in the DB while keeping the actual storage layer decoupled — the helper records what exists, not where.

## What was built

### `Nene\Xion\FileMetadata` (`class/xion/FileMetadata.php`)

DB-backed file metadata manager providing:

| Method | Description |
| --- | --- |
| `register(string $ownerId, string $path, string $filename, string $mimeType, int $sizeBytes, string $storage): int` | Register file. Returns ID. |
| `find(int $id): ?array` | Get by ID (includes deleted). |
| `findByOwner(string $ownerId, ?string $mimeType = null): array` | Active files; optional MIME filter. |
| `delete(int $id, string $ownerId): bool` | Owner-enforced soft delete. |

Key design points:

- **Metadata only**: no file I/O — decoupled from storage backend.
- **`storage` field**: `'local'`, `'s3'`, `'gcs'` etc. — app routes actual deletion.
- **MIME filter**: `findByOwner($u, 'image/')` → `LIKE 'image/%'` for prefix match.
- **Soft delete**: `deleted_at` set; `findByOwner()` excludes; `find()` includes.
- **PDO injection**: `__construct(private readonly ?PDO $db = null)`.

### Tests (`tests/Unit/Xion/FileMetadataTest.php`)

13 unit tests covering:

- register returns id
- register stores metadata (path, filename, mime, size, storage)
- find returns null for unknown id
- find returns row for known id
- find returns soft-deleted file
- findByOwner returns active files
- findByOwner excludes soft-deleted
- findByOwner is owner-isolated
- findByOwner filters by MIME type
- delete returns true by owner
- delete returns false by wrong owner
- delete sets deleted_at
- delete already deleted returns false

### Howto (`docs/development/file-metadata.md`)

Covers: schema, API table, usage, soft delete, MIME filter, key design points, test patterns.

## Findings

### F-1 — No finding (clean trial)

`FileMetadata` is a clean `Nene\Xion` helper. 13 tests pass; CS Fixer and Phan clean.

## Decision

Merge as-is. No follow-up Issues raised.
