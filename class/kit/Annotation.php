<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * Annotation — per-user text highlights and notes over content ranges.
 *
 * Stores highlighted character ranges within a document, each with the
 * highlighted quote and an optional note (Kindle/Hypothes.is-style
 * annotations). Distinct from `EntityComment` (FT214, flat comments on an
 * entity) and `CommentThread` (FT69): this anchors to a character `[start,
 * end)` range inside a document's text.
 *
 * ## Usage
 *
 * ```php
 * $an = new Annotation($pdo);
 *
 * $id = $an->add(userId: 1, document: 'doc-9', start: 100, end: 140,
 *                quote: 'the quick brown fox', note: 'great line');
 *
 * $an->forDocument('doc-9');     // all annotations, ordered by start offset
 * $an->forUser(1, 'doc-9');      // just user 1's
 * $an->updateNote($id, 'edited note');
 * $an->remove($id);
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE annotations (
 *     id           INTEGER PRIMARY KEY AUTOINCREMENT,
 *     user_id      BIGINT       NOT NULL,
 *     document     VARCHAR(190) NOT NULL,
 *     start_offset INTEGER      NOT NULL,
 *     end_offset   INTEGER      NOT NULL,
 *     quote        TEXT         NOT NULL DEFAULT '',
 *     note         TEXT         NOT NULL DEFAULT '',
 *     created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class Annotation
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Add an annotation over a character range `[start, end)`.
     *
     * @param  int    $userId   Annotator user id.
     * @param  string $document Document identifier.
     * @param  int    $start    Start offset (>= 0).
     * @param  int    $end      End offset (> start).
     * @param  string $quote    The highlighted text.
     * @param  string $note     Optional note.
     * @return int              New annotation id.
     * @throws \InvalidArgumentException on empty document or invalid range.
     */
    public function add(int $userId, string $document, int $start, int $end, string $quote = '', string $note = ''): int
    {
        $document = $this->validate($document);
        if ($start < 0) {
            throw new \InvalidArgumentException('Start offset must not be negative.');
        }
        if ($end <= $start) {
            throw new \InvalidArgumentException('End offset must be greater than start.');
        }

        $stmt = $this->db()->prepare(
            'INSERT INTO annotations (user_id, document, start_offset, end_offset, quote, note)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $document, $start, $end, $quote, $note]);

        return (int)$this->db()->lastInsertId();
    }

    /**
     * Fetch a single annotation by id.
     *
     * @return array{id:int,user_id:int,document:string,start:int,end:int,quote:string,note:string}|null
     */
    public function get(int $id): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, user_id, document, start_offset, end_offset, quote, note FROM annotations WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * All annotations on a document (any user), ordered by start then id.
     *
     * @return array<int,array{id:int,user_id:int,document:string,start:int,end:int,quote:string,note:string}>
     */
    public function forDocument(string $document): array
    {
        return $this->query('document = ?', [$this->validate($document)]);
    }

    /**
     * A single user's annotations on a document, ordered by start then id.
     *
     * @return array<int,array{id:int,user_id:int,document:string,start:int,end:int,quote:string,note:string}>
     */
    public function forUser(int $userId, string $document): array
    {
        return $this->query('document = ? AND user_id = ?', [$this->validate($document), $userId]);
    }

    /**
     * Number of annotations on a document.
     */
    public function countFor(string $document): int
    {
        $stmt = $this->db()->prepare('SELECT COUNT(*) FROM annotations WHERE document = ?');
        $stmt->execute([$this->validate($document)]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Update an annotation's note. Returns true if a row was changed.
     */
    public function updateNote(int $id, string $note): bool
    {
        $stmt = $this->db()->prepare('UPDATE annotations SET note = ? WHERE id = ?');
        $stmt->execute([$note, $id]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Delete an annotation. No-op if absent.
     */
    public function remove(int $id): void
    {
        $stmt = $this->db()->prepare('DELETE FROM annotations WHERE id = ?');
        $stmt->execute([$id]);
    }

    // ── private ───────────────────────────────────────────────────────────────

    /**
     * @param  array<int,mixed> $params
     * @return array<int,array{id:int,user_id:int,document:string,start:int,end:int,quote:string,note:string}>
     */
    private function query(string $where, array $params): array
    {
        $stmt = $this->db()->prepare(
            "SELECT id, user_id, document, start_offset, end_offset, quote, note FROM annotations
             WHERE {$where} ORDER BY start_offset ASC, id ASC"
        );
        $stmt->execute($params);

        $out = [];
        /** @var array<string,mixed> $row */
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = $this->hydrate($row);
        }

        return $out;
    }

    /**
     * @param  array<string,mixed> $row
     * @return array{id:int,user_id:int,document:string,start:int,end:int,quote:string,note:string}
     */
    private function hydrate(array $row): array
    {
        return [
            'id'       => (int)$row['id'],
            'user_id'  => (int)$row['user_id'],
            'document' => (string)$row['document'],
            'start'    => (int)$row['start_offset'],
            'end'      => (int)$row['end_offset'],
            'quote'    => (string)$row['quote'],
            'note'     => (string)$row['note'],
        ];
    }

    private function validate(string $document): string
    {
        $document = trim($document);
        if ($document === '') {
            throw new \InvalidArgumentException('Document must not be empty.');
        }

        return $document;
    }

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
