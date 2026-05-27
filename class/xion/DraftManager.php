<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * DraftManager — versioned draft persistence for any content type.
 *
 * Users save drafts while composing content; each save creates a new version.
 * The latest version is the "current" draft. Supports restore to any past version.
 *
 * ## Usage
 *
 * ```php
 * $dm = new DraftManager($pdo);
 *
 * // Save a draft (auto-increments version)
 * $dm->save('post', 'user-1', 'Draft title', ['body' => 'Hello world']);
 *
 * // Get latest draft
 * $dm->latest('post', 'user-1');
 *
 * // Get specific version
 * $dm->version('post', 'user-1', 2);
 *
 * // List all versions
 * $dm->history('post', 'user-1');
 *
 * // Discard all drafts
 * $dm->discard('post', 'user-1');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE drafts (
 *     id           INTEGER PRIMARY KEY AUTOINCREMENT,
 *     draft_type   VARCHAR(100) NOT NULL,
 *     user_id      VARCHAR(255) NOT NULL,
 *     version      INTEGER      NOT NULL DEFAULT 1,
 *     title        VARCHAR(500) NOT NULL DEFAULT '',
 *     content      TEXT         NOT NULL DEFAULT '{}',
 *     saved_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class DraftManager
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Save a new draft version.
     *
     * Each call increments the version number for this (draft_type, user_id) pair.
     *
     * @param  array<string,mixed> $content  Arbitrary content payload (stored as JSON).
     * @return int  The new version number.
     * @throws \InvalidArgumentException if draft_type or user_id is empty.
     */
    public function save(
        string $draftType,
        string $userId,
        string $title = '',
        array $content = []
    ): int {
        [$draftType, $userId] = $this->normalise($draftType, $userId);

        $currentVersion = $this->currentVersion($draftType, $userId);
        $newVersion     = $currentVersion + 1;
        $contentJson    = json_encode($content, JSON_UNESCAPED_UNICODE) ?: '{}';

        $this->db()->prepare(
            'INSERT INTO drafts (draft_type, user_id, version, title, content)
             VALUES (:type, :uid, :ver, :title, :content)'
        )->execute([
            ':type'    => $draftType,
            ':uid'     => $userId,
            ':ver'     => $newVersion,
            ':title'   => $title,
            ':content' => $contentJson,
        ]);

        return $newVersion;
    }

    /**
     * Get the latest draft for a user.
     *
     * @return array<string,mixed>|null  null if no drafts exist.
     */
    public function latest(string $draftType, string $userId): ?array
    {
        [$draftType, $userId] = $this->normalise($draftType, $userId);
        $stmt = $this->db()->prepare(
            'SELECT id, draft_type, user_id, version, title, content, saved_at
             FROM drafts WHERE draft_type = :type AND user_id = :uid
             ORDER BY version DESC LIMIT 1'
        );
        $stmt->execute([':type' => $draftType, ':uid' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $this->decode($row) : null;
    }

    /**
     * Get a specific version of a draft.
     *
     * @return array<string,mixed>|null
     */
    public function version(string $draftType, string $userId, int $version): ?array
    {
        [$draftType, $userId] = $this->normalise($draftType, $userId);
        $stmt = $this->db()->prepare(
            'SELECT id, draft_type, user_id, version, title, content, saved_at
             FROM drafts WHERE draft_type = :type AND user_id = :uid AND version = :ver LIMIT 1'
        );
        $stmt->execute([':type' => $draftType, ':uid' => $userId, ':ver' => $version]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $this->decode($row) : null;
    }

    /**
     * List all saved versions for a user's draft (newest first).
     *
     * @return list<array<string,mixed>>
     */
    public function history(string $draftType, string $userId): array
    {
        [$draftType, $userId] = $this->normalise($draftType, $userId);
        $stmt = $this->db()->prepare(
            'SELECT id, draft_type, user_id, version, title, content, saved_at
             FROM drafts WHERE draft_type = :type AND user_id = :uid
             ORDER BY version DESC'
        );
        $stmt->execute([':type' => $draftType, ':uid' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(fn (array $r) => $this->decode($r), $rows);
    }

    /**
     * Check whether a user has any draft of this type.
     */
    public function hasDraft(string $draftType, string $userId): bool
    {
        [$draftType, $userId] = $this->normalise($draftType, $userId);
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM drafts WHERE draft_type = :type AND user_id = :uid'
        );
        $stmt->execute([':type' => $draftType, ':uid' => $userId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Count the number of saved versions for a draft.
     */
    public function versionCount(string $draftType, string $userId): int
    {
        [$draftType, $userId] = $this->normalise($draftType, $userId);
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM drafts WHERE draft_type = :type AND user_id = :uid'
        );
        $stmt->execute([':type' => $draftType, ':uid' => $userId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Discard all draft versions for a user.
     *
     * @return int Number of rows deleted.
     */
    public function discard(string $draftType, string $userId): int
    {
        [$draftType, $userId] = $this->normalise($draftType, $userId);
        $stmt = $this->db()->prepare(
            'DELETE FROM drafts WHERE draft_type = :type AND user_id = :uid'
        );
        $stmt->execute([':type' => $draftType, ':uid' => $userId]);
        return $stmt->rowCount();
    }

    /**
     * Prune old versions, keeping only the N most recent.
     *
     * @return int Number of rows deleted.
     */
    public function pruneHistory(string $draftType, string $userId, int $keepVersions = 5): int
    {
        [$draftType, $userId] = $this->normalise($draftType, $userId);
        $keepVersions = max(1, $keepVersions);

        // Find the minimum version to keep
        $stmt = $this->db()->prepare(
            "SELECT version FROM drafts WHERE draft_type = :type AND user_id = :uid
             ORDER BY version DESC LIMIT 1 OFFSET {$keepVersions}"
        );
        $stmt->execute([':type' => $draftType, ':uid' => $userId]);
        $cutoffVersion = $stmt->fetchColumn();

        if ($cutoffVersion === false) {
            return 0; // Fewer than keepVersions versions exist
        }

        $del = $this->db()->prepare(
            'DELETE FROM drafts WHERE draft_type = :type AND user_id = :uid AND version <= :cutoff'
        );
        $del->execute([':type' => $draftType, ':uid' => $userId, ':cutoff' => (int)$cutoffVersion]);
        return $del->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    private function currentVersion(string $draftType, string $userId): int
    {
        $stmt = $this->db()->prepare(
            'SELECT COALESCE(MAX(version), 0) FROM drafts WHERE draft_type = :type AND user_id = :uid'
        );
        $stmt->execute([':type' => $draftType, ':uid' => $userId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * @param  array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function decode(array $row): array
    {
        $decoded = json_decode((string)($row['content'] ?? '{}'), true);
        $row['content'] = is_array($decoded) ? $decoded : [];
        return $row;
    }

    /**
     * @return array{string, string}
     */
    private function normalise(string $draftType, string $userId): array
    {
        $draftType = trim($draftType);
        $userId    = trim($userId);
        if ($draftType === '') {
            throw new \InvalidArgumentException('draft_type must not be empty.');
        }
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }
        return [$draftType, $userId];
    }
}
