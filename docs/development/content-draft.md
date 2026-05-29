# Content Draft

`Nene\Kit\ContentDraft` manages a content lifecycle with three states: `draft → published → archived`. Reverse transitions are not allowed.

## State transitions

```
draft ──publish()──▶ published ──archive()──▶ archived
  ↑
create() always starts here
```

| Transition | Method | Who can do it |
|------------|--------|---------------|
| (new) → draft | `create()` | anyone |
| draft → published | `publish()` | author only |
| published → archived | `archive()` | author only |
| draft ← published | ❌ not allowed | — |
| published ← archived | ❌ not allowed | — |

## Schema

```sql
CREATE TABLE contents (
    id           INTEGER      PRIMARY KEY AUTOINCREMENT,
    author_id    VARCHAR(255) NOT NULL,
    title        VARCHAR(255) NOT NULL,
    body         TEXT         NOT NULL DEFAULT '',
    status       VARCHAR(16)  NOT NULL DEFAULT 'draft',
    published_at DATETIME     DEFAULT NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_contents_published ON contents (status, published_at);
```

## API

| Method | Returns | Description |
|--------|---------|-------------|
| `create(authorId, title, body)` | `int` | Create a draft. Returns row ID. |
| `update(contentId, authorId, title, body)` | `bool` | Edit a draft. Author-enforced. |
| `publish(contentId, authorId)` | `bool` | draft → published. Author-enforced. |
| `archive(contentId, authorId)` | `bool` | published → archived. Author-enforced. |
| `find(contentId, viewerId)` | `array\|null` | Get item. Non-author sees null for draft/archived. |
| `listPublished()` | `array[]` | Published items, newest first. |

## Basic usage

```php
use Nene\Kit\ContentDraft;

$content = new ContentDraft();

// Create a draft
$id = $content->create('user:1', 'My Article', 'Initial draft...');

// Edit (draft only)
if (!$content->update($id, $authorId, 'My Article', 'Revised body...')) {
    // Not found, not a draft, or wrong author → 403/422
}

// Publish
if (!$content->publish($id, $authorId)) {
    // Not a draft or wrong author → 422
}

// Get (non-author sees null for draft/archived)
$item = $content->find($id, viewerId: $userId);
if ($item === null) {
    http_response_code(404); exit;
}

// List published
$published = $content->listPublished();
```

## Access control: 404 not 403 for hidden content

Non-published content returns `null` from `find()` rather than a permission error. Callers should return 404, not 403:

- **403** tells the client the resource exists but access is denied — disclosing that a draft exists.
- **404** hides the existence, preventing content enumeration.

## `ORDER BY published_at DESC, id DESC`

The secondary `id DESC` sort ensures stable ordering when multiple items are published within the same second. Without it, same-second publishes would have indeterminate relative order.

## Status constants

```php
ContentDraft::STATUS_DRAFT     // 'draft'
ContentDraft::STATUS_PUBLISHED // 'published'
ContentDraft::STATUS_ARCHIVED  // 'archived'
```
