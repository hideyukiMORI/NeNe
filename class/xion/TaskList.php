<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * TaskList — simple to-do list with per-user tasks and completion tracking.
 *
 * Tasks belong to a named list (or the default 'inbox'). Each task has a
 * title, optional due date, and a completion flag.
 *
 * ## Usage
 *
 * ```php
 * $tl = new TaskList($pdo);
 *
 * // Add a task
 * $id = $tl->add('user-1', 'Buy groceries', 'inbox');
 *
 * // With due date
 * $id = $tl->add('user-1', 'File taxes', 'work', new \DateTimeImmutable('+7 days'));
 *
 * // Complete a task
 * $tl->complete($id, 'user-1');
 *
 * // List tasks
 * $tl->listTasks('user-1', 'inbox');
 *
 * // Count
 * $tl->count('user-1');
 * $tl->count('user-1', false);  // pending only
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE tasks (
 *     id           INTEGER PRIMARY KEY AUTOINCREMENT,
 *     user_id      VARCHAR(255) NOT NULL,
 *     list_name    VARCHAR(100) NOT NULL DEFAULT 'inbox',
 *     title        TEXT         NOT NULL,
 *     completed    TINYINT(1)   NOT NULL DEFAULT 0,
 *     due_at       DATETIME     DEFAULT NULL,
 *     completed_at DATETIME     DEFAULT NULL,
 *     created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class TaskList
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Add a task to a user's list.
     *
     * @param  string                   $listName  List name (defaults to 'inbox').
     * @param  \DateTimeImmutable|null  $dueAt     Optional due date.
     * @return int The new task ID.
     * @throws \InvalidArgumentException if user_id or title is empty.
     */
    public function add(
        string $userId,
        string $title,
        string $listName = 'inbox',
        ?\DateTimeImmutable $dueAt = null
    ): int {
        $userId   = $this->validateUserId($userId);
        $title    = trim($title);
        $listName = trim($listName) ?: 'inbox';
        if ($title === '') {
            throw new \InvalidArgumentException('title must not be empty.');
        }

        $db = $this->db();
        $db->prepare(
            'INSERT INTO tasks (user_id, list_name, title, due_at)
             VALUES (:uid, :list, :title, :due)'
        )->execute([
            ':uid'   => $userId,
            ':list'  => $listName,
            ':title' => $title,
            ':due'   => $dueAt?->format('Y-m-d H:i:s'),
        ]);
        return (int)$db->lastInsertId();
    }

    /**
     * Mark a task as complete.
     *
     * @return bool True if the task was found, owned by the user, and not yet complete.
     */
    public function complete(int $taskId, string $userId): bool
    {
        $userId = $this->validateUserId($userId);
        $stmt   = $this->db()->prepare(
            'UPDATE tasks
             SET completed = 1, completed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND user_id = :uid AND completed = 0'
        );
        $stmt->execute([':id' => $taskId, ':uid' => $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Reopen a completed task.
     *
     * @return bool True if the task was found, owned by the user, and was complete.
     */
    public function reopen(int $taskId, string $userId): bool
    {
        $userId = $this->validateUserId($userId);
        $stmt   = $this->db()->prepare(
            'UPDATE tasks
             SET completed = 0, completed_at = NULL, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND user_id = :uid AND completed = 1'
        );
        $stmt->execute([':id' => $taskId, ':uid' => $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Update the title of a task.
     *
     * @return bool True if updated.
     */
    public function rename(int $taskId, string $userId, string $newTitle): bool
    {
        $newTitle = trim($newTitle);
        if ($newTitle === '') {
            throw new \InvalidArgumentException('title must not be empty.');
        }
        $userId = $this->validateUserId($userId);
        $stmt   = $this->db()->prepare(
            'UPDATE tasks SET title = :title, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND user_id = :uid'
        );
        $stmt->execute([':title' => $newTitle, ':id' => $taskId, ':uid' => $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Delete a task (hard delete).
     *
     * @return bool True if the task was found and deleted.
     */
    public function delete(int $taskId, string $userId): bool
    {
        $userId = $this->validateUserId($userId);
        $stmt   = $this->db()->prepare('DELETE FROM tasks WHERE id = :id AND user_id = :uid');
        $stmt->execute([':id' => $taskId, ':uid' => $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Get a single task by ID.
     *
     * @return array<string,mixed>|null
     */
    public function find(int $taskId): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, user_id, list_name, title, completed, due_at, completed_at, created_at, updated_at
             FROM tasks WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $taskId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * List tasks for a user (optionally filtered by list).
     *
     * @param  bool|null $completed null = all; true = completed; false = pending.
     * @return list<array<string,mixed>>
     */
    public function listTasks(string $userId, ?string $listName = null, ?bool $completed = null): array
    {
        $userId = $this->validateUserId($userId);
        $params = [':uid' => $userId];
        $sql    = 'SELECT id, user_id, list_name, title, completed, due_at, completed_at, created_at
                   FROM tasks WHERE user_id = :uid';

        if ($listName !== null) {
            $sql             .= ' AND list_name = :list';
            $params[':list']  = $listName;
        }
        if ($completed !== null) {
            $sql               .= ' AND completed = :done';
            $params[':done']    = $completed ? 1 : 0;
        }
        $sql .= ' ORDER BY CASE WHEN due_at IS NULL THEN 1 ELSE 0 END, due_at ASC, id ASC';

        $stmt = $this->db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Count tasks for a user.
     *
     * @param bool|null $completed null = all; true = completed; false = pending.
     */
    public function count(string $userId, ?bool $completed = null): int
    {
        $userId = $this->validateUserId($userId);

        if ($completed === null) {
            $stmt = $this->db()->prepare('SELECT COUNT(*) FROM tasks WHERE user_id = :uid');
            $stmt->execute([':uid' => $userId]);
        } else {
            $stmt = $this->db()->prepare(
                'SELECT COUNT(*) FROM tasks WHERE user_id = :uid AND completed = :done'
            );
            $stmt->execute([':uid' => $userId, ':done' => $completed ? 1 : 0]);
        }
        return (int)$stmt->fetchColumn();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    private function validateUserId(string $userId): string
    {
        $userId = trim($userId);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }
        return $userId;
    }
}
