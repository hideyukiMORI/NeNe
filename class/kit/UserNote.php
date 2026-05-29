<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * UserNote — admin/staff notes attached to a user record.
 *
 * Stores freeform text notes about a user, written by admins or support
 * staff. Notes can be pinned to appear first. Useful for CRM, moderation,
 * and support scenarios where staff need to track context about a user.
 *
 * ## Usage
 *
 * ```php
 * $un = new UserNote($pdo);
 *
 * // Add notes
 * $id = $un->add('user-1', 'admin-1', 'Requested account merge with user-2');
 *
 * // Pin a note for visibility
 * $un->pin($id);
 *
 * // Update / delete
 * $un->update($id, 'Updated context after investigation');
 * $un->delete($id);
 *
 * // List (pinned first, then newest)
 * $notes = $un->forUser('user-1');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE user_notes (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     user_id    VARCHAR(255) NOT NULL,
 *     author_id  VARCHAR(255) NOT NULL,
 *     content    TEXT         NOT NULL,
 *     pinned     TINYINT(1)   NOT NULL DEFAULT 0,
 *     created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class UserNote
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Add a note about a user.
     *
     * @return int Row ID of the new note.
     * @throws \InvalidArgumentException on empty userId or empty content.
     */
    public function add(string $userId, string $authorId, string $content): int
    {
        $userId  = trim($userId);
        $content = trim($content);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }
        if ($content === '') {
            throw new \InvalidArgumentException('content must not be empty.');
        }

        $stmt = $this->db()->prepare(
            'INSERT INTO user_notes (user_id, author_id, content)
             VALUES (:uid, :author, :content)'
        );
        $stmt->execute([
            ':uid'     => $userId,
            ':author'  => trim($authorId),
            ':content' => $content,
        ]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Update the content of an existing note.
     *
     * @return bool True if found and updated.
     * @throws \InvalidArgumentException on empty content.
     */
    public function update(int $id, string $content): bool
    {
        $content = trim($content);
        if ($content === '') {
            throw new \InvalidArgumentException('content must not be empty.');
        }
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'UPDATE user_notes SET content = :content, updated_at = :now WHERE id = :id'
        );
        $stmt->execute([':content' => $content, ':now' => $now, ':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Pin a note (causes it to appear first in forUser()).
     *
     * @return bool True if found and updated.
     */
    public function pin(int $id): bool
    {
        return $this->setPinned($id, true);
    }

    /**
     * Unpin a note.
     *
     * @return bool True if found and updated.
     */
    public function unpin(int $id): bool
    {
        return $this->setPinned($id, false);
    }

    /**
     * Delete a note.
     *
     * @return bool True if found and deleted.
     */
    public function delete(int $id): bool
    {
        $stmt = $this->db()->prepare('DELETE FROM user_notes WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Find a note by ID.
     *
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, user_id, author_id, content, pinned, created_at, updated_at
             FROM user_notes WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * List all notes for a user (pinned first, then newest).
     *
     * @return list<array<string,mixed>>
     */
    public function forUser(string $userId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, user_id, author_id, content, pinned, created_at, updated_at
             FROM user_notes WHERE user_id = :uid
             ORDER BY pinned DESC, id DESC'
        );
        $stmt->execute([':uid' => trim($userId)]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Count notes for a user.
     */
    public function count(string $userId): int
    {
        $stmt = $this->db()->prepare('SELECT COUNT(*) FROM user_notes WHERE user_id = :uid');
        $stmt->execute([':uid' => trim($userId)]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Delete all notes for a user.
     *
     * @return int Number of rows deleted.
     */
    public function deleteAllForUser(string $userId): int
    {
        $stmt = $this->db()->prepare('DELETE FROM user_notes WHERE user_id = :uid');
        $stmt->execute([':uid' => trim($userId)]);
        return $stmt->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    private function setPinned(int $id, bool $pinned): bool
    {
        $stmt = $this->db()->prepare('UPDATE user_notes SET pinned = :pinned WHERE id = :id');
        $stmt->execute([':pinned' => $pinned ? 1 : 0, ':id' => $id]);
        return $stmt->rowCount() > 0;
    }
}
