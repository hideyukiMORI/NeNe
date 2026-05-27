<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * VotePoll — simple polls with named options and one-vote-per-user enforcement.
 *
 * Supports creating polls with multiple options, recording votes, and querying
 * vote counts. Each user may cast exactly one vote per poll; voting again
 * replaces their previous choice (or detects duplicates, depending on use case).
 * Here we use an idempotent upsert so repeated voting for the same option is
 * a no-op and voting for a different option changes the vote.
 *
 * ## Usage
 *
 * ```php
 * $vp = new VotePoll($pdo);
 *
 * // Create a poll
 * $pollId = $vp->create('Favourite colour?', ['Red', 'Blue', 'Green']);
 *
 * // Cast / change a vote
 * $vp->vote($pollId, 'user-1', 'Red');
 * $vp->vote($pollId, 'user-1', 'Blue'); // changes their vote
 *
 * // Results
 * $results = $vp->results($pollId);
 * // [['option' => 'Blue', 'votes' => 1], ...]
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE vote_polls (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     question   TEXT         NOT NULL,
 *     options    TEXT         NOT NULL,
 *     status     VARCHAR(20)  NOT NULL DEFAULT 'open',
 *     created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 *
 * CREATE TABLE vote_poll_votes (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     poll_id    INTEGER      NOT NULL,
 *     user_id    VARCHAR(255) NOT NULL,
 *     option     VARCHAR(255) NOT NULL,
 *     voted_at   DATETIME     NOT NULL,
 *     UNIQUE (poll_id, user_id)
 * );
 * ```
 */
final class VotePoll
{
    public const STATUS_OPEN   = 'open';
    public const STATUS_CLOSED = 'closed';

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Create a poll with a list of options.
     *
     * @param list<string> $options Poll options (at least 2).
     * @return int New poll ID.
     * @throws \InvalidArgumentException on empty question or fewer than 2 options.
     */
    public function create(string $question, array $options): int
    {
        $question = trim($question);
        if ($question === '') {
            throw new \InvalidArgumentException('question must not be empty.');
        }
        $options = array_values(array_filter(array_map('trim', $options), fn (string $o) => $o !== ''));
        if (count($options) < 2) {
            throw new \InvalidArgumentException('at least 2 non-empty options are required.');
        }

        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'INSERT INTO vote_polls (question, options, status, created_at)
             VALUES (:q, :opts, :status, :now)'
        );
        $stmt->execute([
            ':q'      => $question,
            ':opts'   => json_encode($options, JSON_UNESCAPED_UNICODE),
            ':status' => self::STATUS_OPEN,
            ':now'    => $now,
        ]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Retrieve a poll by ID.
     *
     * @return array<string,mixed>|null
     */
    public function get(int $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM vote_polls WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Cast or change a user's vote on a poll.
     *
     * - Throws \RuntimeException if the poll is closed.
     * - Throws \InvalidArgumentException if the option is not in the poll's option list.
     * - If the user already voted for this option, the call is a no-op.
     * - If the user previously voted for a different option, their vote is updated.
     *
     * @throws \RuntimeException if poll not found or is closed.
     * @throws \InvalidArgumentException if option is not valid for this poll.
     */
    public function vote(int $pollId, string $userId, string $option): void
    {
        $userId = trim($userId);
        $option = trim($option);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }

        $poll = $this->get($pollId);
        if ($poll === null) {
            throw new \RuntimeException('Poll not found.');
        }
        if ($poll['status'] !== self::STATUS_OPEN) {
            throw new \RuntimeException('Poll is closed.');
        }

        /** @var list<string> $options */
        $options = json_decode((string)$poll['options'], true);
        if (!in_array($option, $options, true)) {
            throw new \InvalidArgumentException("Option '{$option}' is not valid for this poll.");
        }

        $now    = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $driver = $this->db()->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $this->db()->prepare(
                'INSERT INTO vote_poll_votes (poll_id, user_id, option, voted_at)
                 VALUES (:pid, :uid, :opt, :now)
                 ON CONFLICT (poll_id, user_id) DO UPDATE SET
                     option   = excluded.option,
                     voted_at = excluded.voted_at'
            );
        } else {
            $stmt = $this->db()->prepare(
                'INSERT INTO vote_poll_votes (poll_id, user_id, option, voted_at)
                 VALUES (:pid, :uid, :opt, :now)
                 ON DUPLICATE KEY UPDATE
                     option   = VALUES(option),
                     voted_at = VALUES(voted_at)'
            );
        }
        $stmt->execute([':pid' => $pollId, ':uid' => $userId, ':opt' => $option, ':now' => $now]);
    }

    /**
     * Return vote counts per option for a poll, ordered by votes descending.
     *
     * @return list<array{option: string, votes: int}>
     */
    public function results(int $pollId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT option, COUNT(*) AS votes
             FROM vote_poll_votes
             WHERE poll_id = :pid
             GROUP BY option
             ORDER BY votes DESC, option ASC'
        );
        $stmt->execute([':pid' => $pollId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === false) {
            return [];
        }
        return array_map(
            fn (array $r) => ['option' => (string)$r['option'], 'votes' => (int)$r['votes']],
            $rows
        );
    }

    /**
     * Return the option a user voted for, or null if they haven't voted.
     */
    public function userVote(int $pollId, string $userId): ?string
    {
        $stmt = $this->db()->prepare(
            'SELECT option FROM vote_poll_votes WHERE poll_id = :pid AND user_id = :uid'
        );
        $stmt->execute([':pid' => $pollId, ':uid' => trim($userId)]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (string)$val : null;
    }

    /**
     * Total number of votes cast for a poll.
     */
    public function totalVotes(int $pollId): int
    {
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM vote_poll_votes WHERE poll_id = :pid'
        );
        $stmt->execute([':pid' => $pollId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Close a poll (no more votes accepted).
     *
     * @return bool True if found and updated.
     */
    public function close(int $pollId): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE vote_polls SET status = :status WHERE id = :id'
        );
        $stmt->execute([':status' => self::STATUS_CLOSED, ':id' => $pollId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Delete a poll and all its votes.
     *
     * @return bool True if the poll row was found and deleted.
     */
    public function delete(int $pollId): bool
    {
        $stmt = $this->db()->prepare('DELETE FROM vote_poll_votes WHERE poll_id = :pid');
        $stmt->execute([':pid' => $pollId]);

        $stmt = $this->db()->prepare('DELETE FROM vote_polls WHERE id = :id');
        $stmt->execute([':id' => $pollId]);
        return $stmt->rowCount() > 0;
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
