<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * PinnedItem — pin entities to the top of a context, in an ordered list.
 *
 * Maintains a small ordered set of "pinned" items per context (pinned posts in
 * a forum, pinned messages in a channel, featured products on a page). New
 * pins append to the end; `moveToTop()` / `moveToBottom()` reorder. Distinct
 * from `Bookmark` (FT72, private saved items) and `Watchlist` (FT80): this is
 * a shared, ordered, curated list.
 *
 * ## Usage
 *
 * ```php
 * $p = new PinnedItem($pdo);
 *
 * $p->pin('channel:5', 'msg-100', pinnedBy: 7);
 * $p->pin('channel:5', 'msg-200');
 *
 * $p->items('channel:5');          // ['msg-100', 'msg-200'] (pin order)
 * $p->moveToTop('channel:5', 'msg-200'); // ['msg-200', 'msg-100']
 * $p->isPinned('channel:5', 'msg-100');  // true
 * $p->unpin('channel:5', 'msg-100');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE pinned_items (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     context    VARCHAR(150) NOT NULL,
 *     item       VARCHAR(190) NOT NULL,
 *     position   INTEGER      NOT NULL DEFAULT 0,
 *     pinned_by  BIGINT       NOT NULL DEFAULT 0,
 *     created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (context, item)
 * );
 * ```
 */
final class PinnedItem
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Pin an item to a context (appended to the end). Idempotent: re-pinning an
     * existing item keeps its position.
     *
     * @param  string $context  Context key.
     * @param  string $item     Item identifier.
     * @param  int    $pinnedBy Optional user id who pinned it.
     * @throws \InvalidArgumentException on empty context or item.
     */
    public function pin(string $context, string $item, int $pinnedBy = 0): void
    {
        $context = $this->validate($context, 'Context');
        $item    = $this->validate($item, 'Item');
        if ($this->isPinned($context, $item)) {
            return;
        }

        $stmt = $this->db()->prepare(
            'INSERT INTO pinned_items (context, item, position, pinned_by) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$context, $item, $this->maxPosition($context) + 1, $pinnedBy]);
    }

    /**
     * Unpin an item. No-op if not pinned.
     */
    public function unpin(string $context, string $item): void
    {
        $context = $this->validate($context, 'Context');
        $item    = $this->validate($item, 'Item');
        $stmt    = $this->db()->prepare('DELETE FROM pinned_items WHERE context = ? AND item = ?');
        $stmt->execute([$context, $item]);
    }

    /**
     * Whether an item is pinned in a context.
     */
    public function isPinned(string $context, string $item): bool
    {
        $context = $this->validate($context, 'Context');
        $item    = $this->validate($item, 'Item');
        $stmt    = $this->db()->prepare('SELECT 1 FROM pinned_items WHERE context = ? AND item = ? LIMIT 1');
        $stmt->execute([$context, $item]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Pinned items for a context, in pin order (position asc).
     *
     * @return array<int,string>
     */
    public function items(string $context): array
    {
        $context = $this->validate($context, 'Context');
        $stmt    = $this->db()->prepare(
            'SELECT item FROM pinned_items WHERE context = ? ORDER BY position ASC, id ASC'
        );
        $stmt->execute([$context]);

        return array_map(static fn ($i): string => (string)$i, $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Number of pinned items in a context.
     */
    public function count(string $context): int
    {
        $context = $this->validate($context, 'Context');
        $stmt    = $this->db()->prepare('SELECT COUNT(*) FROM pinned_items WHERE context = ?');
        $stmt->execute([$context]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Move a pinned item to the top of its context. No-op if not pinned.
     */
    public function moveToTop(string $context, string $item): void
    {
        $this->moveTo($context, $item, $this->minPosition($context) - 1);
    }

    /**
     * Move a pinned item to the bottom of its context. No-op if not pinned.
     */
    public function moveToBottom(string $context, string $item): void
    {
        $this->moveTo($context, $item, $this->maxPosition($context) + 1);
    }

    /**
     * Remove all pins in a context. Returns the number removed.
     */
    public function clear(string $context): int
    {
        $context = $this->validate($context, 'Context');
        $stmt    = $this->db()->prepare('DELETE FROM pinned_items WHERE context = ?');
        $stmt->execute([$context]);

        return $stmt->rowCount();
    }

    // ── private ───────────────────────────────────────────────────────────────

    private function moveTo(string $context, string $item, int $position): void
    {
        $context = $this->validate($context, 'Context');
        $item    = $this->validate($item, 'Item');
        $stmt    = $this->db()->prepare('UPDATE pinned_items SET position = ? WHERE context = ? AND item = ?');
        $stmt->execute([$position, $context, $item]);
    }

    private function maxPosition(string $context): int
    {
        $stmt = $this->db()->prepare('SELECT COALESCE(MAX(position), -1) FROM pinned_items WHERE context = ?');
        $stmt->execute([$context]);

        return (int)$stmt->fetchColumn();
    }

    private function minPosition(string $context): int
    {
        $stmt = $this->db()->prepare('SELECT COALESCE(MIN(position), 0) FROM pinned_items WHERE context = ?');
        $stmt->execute([$context]);

        return (int)$stmt->fetchColumn();
    }

    private function validate(string $value, string $label): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new \InvalidArgumentException("{$label} must not be empty.");
        }

        return $value;
    }

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
