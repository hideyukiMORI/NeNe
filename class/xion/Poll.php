<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * Polls and surveys — create questions, vote, and tally results.
 *
 * Supports single-choice polls. Each user may cast at most one vote per poll
 * (INSERT OR IGNORE / INSERT IGNORE enforces the constraint).
 *
 * ## Usage
 *
 * ```php
 * $poll = new Poll($pdo);
 *
 * // Create poll with options
 * $pollId  = $poll->create('Which framework?', ['NeNe', 'Laravel', 'Symfony']);
 * $options = $poll->options($pollId);
 *
 * // Vote
 * $optionId = $options[0]['id'];
 * $poll->vote($pollId, $optionId, 'user-1');
 *
 * // Results
 * $results = $poll->results($pollId);
 * // [['option_id' => ..., 'label' => 'NeNe', 'votes' => 1, 'percent' => 100.0], ...]
 *
 * // Check if voted
 * $poll->hasVoted($pollId, 'user-1'); // true
 * $poll->userVote($pollId, 'user-1'); // ['option_id' => ..., 'label' => 'NeNe']
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE polls (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     question   TEXT        NOT NULL,
 *     created_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 *
 * CREATE TABLE poll_options (
 *     id      INTEGER PRIMARY KEY AUTOINCREMENT,
 *     poll_id INTEGER     NOT NULL,
 *     label   VARCHAR(255) NOT NULL,
 *     sort    INTEGER     NOT NULL DEFAULT 0
 * );
 *
 * CREATE TABLE poll_votes (
 *     poll_id   INTEGER      NOT NULL,
 *     option_id INTEGER      NOT NULL,
 *     user_id   VARCHAR(255) NOT NULL,
 *     voted_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     PRIMARY KEY (poll_id, user_id)
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
     * Create a poll with a question and a list of option labels.
     *
     * @param  string        $question Non-empty question text.
     * @param  list<string>  $options  Two or more non-empty option labels.
     * @return int           New poll ID.
     * @throws \InvalidArgumentException if question is empty or fewer than 2 options are given.
     */
    public function create(string $question, array $options): int
    {
        $question = trim($question);
        if ($question === '') {
            throw new \InvalidArgumentException('Poll question must not be empty.');
        }

        $options = array_values(array_filter(array_map('trim', $options), static fn (string $o): bool => $o !== ''));
        if (count($options) < 2) {
            throw new \InvalidArgumentException('A poll requires at least 2 options.');
        }

        $db = $this->db();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare('INSERT INTO polls (question) VALUES (:q)');
            $stmt->execute([':q' => $question]);
            $pollId = (int)$db->lastInsertId();

            $optStmt = $db->prepare('INSERT INTO poll_options (poll_id, label, sort) VALUES (:poll, :label, :sort)');
            foreach ($options as $i => $label) {
                $optStmt->execute([':poll' => $pollId, ':label' => $label, ':sort' => $i]);
            }

            $db->commit();
            return $pollId;
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * Return the options for a poll, ordered by sort index.
     *
     * @return list<array<string, mixed>>
     */
    public function options(int $pollId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM poll_options WHERE poll_id = :poll ORDER BY sort ASC, id ASC'
        );
        $stmt->execute([':poll' => $pollId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Cast a vote for an option in a poll.
     *
     * Idempotent for the same (pollId, userId) pair — a second call for the same
     * user is silently ignored (INSERT OR IGNORE).
     *
     * @throws \InvalidArgumentException if the option does not belong to the poll.
     */
    public function vote(int $pollId, int $optionId, string $userId): bool
    {
        // Verify the option belongs to this poll
        $check = $this->db()->prepare(
            'SELECT 1 FROM poll_options WHERE id = :opt AND poll_id = :poll LIMIT 1'
        );
        $check->execute([':opt' => $optionId, ':poll' => $pollId]);
        if ($check->fetchColumn() === false) {
            throw new \InvalidArgumentException("Option #{$optionId} does not belong to poll #{$pollId}.");
        }

        $db     = $this->db();
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql    = $driver === 'sqlite'
            ? 'INSERT OR IGNORE INTO poll_votes (poll_id, option_id, user_id) VALUES (:poll, :opt, :user)'
            : 'INSERT IGNORE INTO poll_votes (poll_id, option_id, user_id) VALUES (:poll, :opt, :user)';

        $stmt = $db->prepare($sql);
        $stmt->execute([':poll' => $pollId, ':opt' => $optionId, ':user' => $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Return vote tallies for all options in a poll.
     *
     * @return list<array{option_id: int, label: string, votes: int, percent: float}>
     */
    public function results(int $pollId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT o.id AS option_id, o.label,
                    COUNT(v.user_id) AS votes
             FROM poll_options o
             LEFT JOIN poll_votes v ON v.option_id = o.id AND v.poll_id = o.poll_id
             WHERE o.poll_id = :poll
             GROUP BY o.id, o.label
             ORDER BY o.sort ASC, o.id ASC'
        );
        $stmt->execute([':poll' => $pollId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $total = array_sum(array_column($rows, 'votes'));

        return array_map(
            static function (array $r) use ($total): array {
                $votes = (int)$r['votes'];
                return [
                    'option_id' => (int)$r['option_id'],
                    'label'     => $r['label'],
                    'votes'     => $votes,
                    'percent'   => $total > 0 ? round($votes / $total * 100, 1) : 0.0,
                ];
            },
            $rows
        );
    }

    /**
     * Check whether a user has already voted in a poll.
     */
    public function hasVoted(int $pollId, string $userId): bool
    {
        $stmt = $this->db()->prepare(
            'SELECT 1 FROM poll_votes WHERE poll_id = :poll AND user_id = :user LIMIT 1'
        );
        $stmt->execute([':poll' => $pollId, ':user' => $userId]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Return the option a user voted for, or null if they haven't voted.
     *
     * @return array{option_id: int, label: string}|null
     */
    public function userVote(int $pollId, string $userId): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT o.id AS option_id, o.label
             FROM poll_votes v
             JOIN poll_options o ON o.id = v.option_id
             WHERE v.poll_id = :poll AND v.user_id = :user
             LIMIT 1'
        );
        $stmt->execute([':poll' => $pollId, ':user' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? ['option_id' => (int)$row['option_id'], 'label' => $row['label']] : null;
    }

    /**
     * Get a poll record by ID, or null if not found.
     *
     * @return array<string, mixed>|null
     */
    public function get(int $pollId): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM polls WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $pollId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
