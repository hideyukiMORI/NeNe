<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * SessionFlash — one-time flash messages stored in DB, consumed on next read.
 *
 * Flash messages are written with a session token and a category (e.g. 'success',
 * 'error', 'info'). On the next page load the caller reads them — each message
 * is returned exactly once and then deleted.
 *
 * Suitable for post/redirect/get patterns where the PHP session is unavailable
 * (e.g. API-first apps, multi-server deployments sharing a DB).
 *
 * ## Usage
 *
 * ```php
 * $sf = new SessionFlash($pdo);
 *
 * // Write flash messages (before redirect)
 * $sf->add('tok-abc', 'success', 'Profile saved.');
 * $sf->add('tok-abc', 'info', 'Email verification sent.');
 *
 * // Read and consume (after redirect)
 * $messages = $sf->consume('tok-abc'); // returns all, then deletes them
 *
 * // Or consume only one category
 * $errors = $sf->consumeCategory('tok-abc', 'error');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE session_flash (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     token      VARCHAR(255) NOT NULL,
 *     category   VARCHAR(50)  NOT NULL DEFAULT 'info',
 *     message    TEXT         NOT NULL DEFAULT '',
 *     created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class SessionFlash
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Add a flash message.
     *
     * @return int The new record ID.
     * @throws \InvalidArgumentException if token is empty.
     */
    public function add(string $token, string $category, string $message): int
    {
        $token = $this->validateToken($token);
        $db    = $this->db();

        $db->prepare(
            'INSERT INTO session_flash (token, category, message) VALUES (:token, :cat, :msg)'
        )->execute([':token' => $token, ':cat' => $category, ':msg' => $message]);
        return (int)$db->lastInsertId();
    }

    /**
     * Consume all flash messages for a token (returns them and deletes them).
     *
     * @return list<array{id: int, category: string, message: string}>
     */
    public function consume(string $token): array
    {
        $token = $this->validateToken($token);
        $db    = $this->db();

        $stmt = $db->prepare(
            'SELECT id, category, message FROM session_flash
             WHERE token = :token ORDER BY id ASC'
        );
        $stmt->execute([':token' => $token]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (!empty($rows)) {
            $db->prepare('DELETE FROM session_flash WHERE token = :token')->execute([':token' => $token]);
        }

        return array_map(
            static fn (array $r) => [
                'id'       => (int)$r['id'],
                'category' => (string)$r['category'],
                'message'  => (string)$r['message'],
            ],
            $rows
        );
    }

    /**
     * Consume flash messages of a specific category (returns and deletes them).
     *
     * @return list<array{id: int, category: string, message: string}>
     */
    public function consumeCategory(string $token, string $category): array
    {
        $token = $this->validateToken($token);
        $db    = $this->db();

        $stmt = $db->prepare(
            'SELECT id, category, message FROM session_flash
             WHERE token = :token AND category = :cat ORDER BY id ASC'
        );
        $stmt->execute([':token' => $token, ':cat' => $category]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (!empty($rows)) {
            $ids = array_column($rows, 'id');
            $in  = implode(',', array_fill(0, count($ids), '?'));
            $db->prepare("DELETE FROM session_flash WHERE id IN ({$in})")->execute($ids);
        }

        return array_map(
            static fn (array $r) => [
                'id'       => (int)$r['id'],
                'category' => (string)$r['category'],
                'message'  => (string)$r['message'],
            ],
            $rows
        );
    }

    /**
     * Peek at flash messages without consuming them.
     *
     * @return list<array{id: int, category: string, message: string}>
     */
    public function peek(string $token): array
    {
        $token = $this->validateToken($token);
        $stmt  = $this->db()->prepare(
            'SELECT id, category, message FROM session_flash
             WHERE token = :token ORDER BY id ASC'
        );
        $stmt->execute([':token' => $token]);
        return array_map(
            static fn (array $r) => [
                'id'       => (int)$r['id'],
                'category' => (string)$r['category'],
                'message'  => (string)$r['message'],
            ],
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * Check whether any flash messages exist for a token.
     */
    public function has(string $token, ?string $category = null): bool
    {
        $token = $this->validateToken($token);

        if ($category !== null) {
            $stmt = $this->db()->prepare(
                'SELECT COUNT(*) FROM session_flash WHERE token = :token AND category = :cat'
            );
            $stmt->execute([':token' => $token, ':cat' => $category]);
        } else {
            $stmt = $this->db()->prepare(
                'SELECT COUNT(*) FROM session_flash WHERE token = :token'
            );
            $stmt->execute([':token' => $token]);
        }
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Count flash messages for a token.
     */
    public function count(string $token): int
    {
        $token = $this->validateToken($token);
        $stmt  = $this->db()->prepare('SELECT COUNT(*) FROM session_flash WHERE token = :token');
        $stmt->execute([':token' => $token]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Purge messages older than N days (for cleanup jobs).
     *
     * @return int Number of rows deleted.
     */
    public function purgeOlderThan(int $days): int
    {
        $cutoff = (new \DateTimeImmutable())->modify("-{$days} days")->format('Y-m-d H:i:s');
        $stmt   = $this->db()->prepare('DELETE FROM session_flash WHERE created_at < :cutoff');
        $stmt->execute([':cutoff' => $cutoff]);
        return $stmt->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    private function validateToken(string $token): string
    {
        $token = trim($token);
        if ($token === '') {
            throw new \InvalidArgumentException('token must not be empty.');
        }
        return $token;
    }
}
