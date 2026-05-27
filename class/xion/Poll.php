<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * Poll — create polls with options and record per-user votes.
 *
 * Each poll has named options. A user can vote for one option
 * (or multiple if the poll is multi-choice). Votes are stored once
 * per (poll_id, user_id, option_key) combination.
 *
 * ## Usage
 *
 * ```php
 * $poll = new Poll($pdo);
 *
 * // Create a poll
 * $id = $poll->create('Favourite colour?', ['red', 'green', 'blue']);
 *
 * // Vote
 * $poll->vote($id, 'user-1', 'red');
 *
 * // Get results
 * $poll->results($id);  // ['red' => 1, 'green' => 0, 'blue' => 0]
 *
 * // Check if user voted
 * $poll->hasVoted($id, 'user-1');       // true
 * $poll->votedFor($id, 'user-1');       // ['red']
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE polls (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     question   TEXT    NOT NULL,
 *     options    TEXT    NOT NULL DEFAULT '[]',
 *     closed_at  DATETIME DEFAULT NULL,
 *     created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 *
 * CREATE TABLE poll_votes (
 *     poll_id    INTEGER      NOT NULL,
 *     user_id    VARCHAR(255) NOT NULL,
 *     option_key VARCHAR(100) NOT NULL,
 *     voted_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     PRIMARY KEY (poll_id, user_id, option_key)
 * );
 * ```
 */
final class Poll
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Create a new poll.
     *
     * @param  list<string> $options Non-empty list of option keys.
     * @return int The new poll ID.
     * @throws \InvalidArgumentException if question or options is empty.
     */
    public function create(string $question, array $options): int
    {
        $question = trim($question);
        if ($question === '') {
            throw new \InvalidArgumentException('question must not be empty.');
        }
        if (empty($options)) {
            throw new \InvalidArgumentException('options must not be empty.');
        }

        $db = $this->db();
        $db->prepare(
            'INSERT INTO polls (question, options) VALUES (:q, :opts)'
        )->execute([
            ':q'    => $question,
            ':opts' => json_encode(array_values($options), JSON_THROW_ON_ERROR),
        ]);
        return (int)$db->lastInsertId();
    }

    /**
     * Cast a vote.
     *
     * Idempotent — voting for the same option again has no effect.
     *
     * @return bool True if the vote was recorded; false if already voted for this option.
     * @throws \InvalidArgumentException if poll is closed or option is invalid.
     */
    public function vote(int $pollId, string $userId, string $optionKey): bool
    {
        $userId    = trim($userId);
        $optionKey = trim($optionKey);

        $poll = $this->find($pollId);
        if ($poll === null) {
            throw new \InvalidArgumentException("Poll {$pollId} not found.");
        }
        if ($poll['closed_at'] !== null) {
            throw new \InvalidArgumentException("Poll {$pollId} is closed.");
        }
        if (!in_array($optionKey, $poll['options'], true)) {
            throw new \InvalidArgumentException("Invalid option '{$optionKey}'.");
        }

        $db     = $this->db();
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $stmt = $db->prepare(
                'INSERT OR IGNORE INTO poll_votes (poll_id, user_id, option_key) VALUES (:pid, :uid, :opt)'
            );
        } else {
            $stmt = $db->prepare(
                'INSERT IGNORE INTO poll_votes (poll_id, user_id, option_key) VALUES (:pid, :uid, :opt)'
            );
        }
        $stmt->execute([':pid' => $pollId, ':uid' => $userId, ':opt' => $optionKey]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Close a poll (no more votes accepted).
     *
     * @return bool True if the poll was open and is now closed.
     */
    public function close(int $pollId): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE polls SET closed_at = CURRENT_TIMESTAMP WHERE id = :id AND closed_at IS NULL'
        );
        $stmt->execute([':id' => $pollId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Get a poll (with options decoded).
     *
     * @return array<string,mixed>|null
     */
    public function find(int $pollId): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, question, options, closed_at, created_at FROM polls WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $pollId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row['options'] = json_decode((string)$row['options'], true) ?? [];
        return $row;
    }

    /**
     * Get vote counts per option.
     *
     * @return array<string,int>
     */
    public function results(int $pollId): array
    {
        $poll = $this->find($pollId);
        if ($poll === null) {
            return [];
        }

        // Initialise all options to 0
        $counts = array_fill_keys($poll['options'], 0);

        $stmt = $this->db()->prepare(
            'SELECT option_key, COUNT(*) AS cnt FROM poll_votes
             WHERE poll_id = :pid GROUP BY option_key'
        );
        $stmt->execute([':pid' => $pollId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $counts[(string)$row['option_key']] = (int)$row['cnt'];
        }
        return $counts;
    }

    /**
     * Check whether a user has voted in a poll.
     */
    public function hasVoted(int $pollId, string $userId): bool
    {
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM poll_votes WHERE poll_id = :pid AND user_id = :uid'
        );
        $stmt->execute([':pid' => $pollId, ':uid' => $userId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Get the options a user voted for.
     *
     * @return list<string>
     */
    public function votedFor(int $pollId, string $userId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT option_key FROM poll_votes WHERE poll_id = :pid AND user_id = :uid ORDER BY voted_at ASC'
        );
        $stmt->execute([':pid' => $pollId, ':uid' => $userId]);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], 'option_key');
    }

    /**
     * Total number of votes cast for a poll.
     */
    public function totalVotes(int $pollId): int
    {
        $stmt = $this->db()->prepare('SELECT COUNT(*) FROM poll_votes WHERE poll_id = :pid');
        $stmt->execute([':pid' => $pollId]);
        return (int)$stmt->fetchColumn();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
