<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * QuizAttempt — record and score quiz / assessment attempts per user.
 *
 * Logs each attempt at a named quiz with a raw score, the maximum possible
 * score, and whether it met the pass mark. Supports best-score, has-passed,
 * attempt history, and a per-quiz pass rate. Distinct from `SurveyResponse`
 * (FT215, un-scored form answers) and `VotePoll` (FT239, voting).
 *
 * Attempts are append-only; a user may attempt a quiz many times.
 *
 * ## Usage
 *
 * ```php
 * $q = new QuizAttempt($pdo);
 *
 * $q->record('php-basics', userId: 1, score: 7, maxScore: 10, passMark: 6); // passed
 * $q->record('php-basics', userId: 1, score: 5, maxScore: 10, passMark: 6); // failed
 *
 * $q->bestScore('php-basics', 1);   // 7
 * $q->hasPassed('php-basics', 1);   // true (one attempt passed)
 * $q->attemptCount('php-basics', 1);// 2
 * $q->passRate('php-basics');       // 0.5 (1 of 2 attempts passed)
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE quiz_attempts (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     quiz       VARCHAR(150) NOT NULL,
 *     user_id    BIGINT       NOT NULL,
 *     score      INTEGER      NOT NULL,
 *     max_score  INTEGER      NOT NULL,
 *     passed     INTEGER      NOT NULL DEFAULT 0,
 *     created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class QuizAttempt
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Record a quiz attempt. Pass/fail is `score >= passMark`.
     *
     * @param  string $quiz     Quiz identifier.
     * @param  int    $userId   User id.
     * @param  int    $score    Raw score (0 <= score <= maxScore).
     * @param  int    $maxScore Maximum possible score (> 0).
     * @param  int    $passMark Minimum score to pass (0 <= passMark <= maxScore).
     * @return int              New attempt id.
     * @throws \InvalidArgumentException on empty quiz or out-of-range scores.
     */
    public function record(string $quiz, int $userId, int $score, int $maxScore, int $passMark): int
    {
        $quiz = $this->validate($quiz);
        if ($maxScore <= 0) {
            throw new \InvalidArgumentException('Max score must be positive.');
        }
        if ($score < 0 || $score > $maxScore) {
            throw new \InvalidArgumentException('Score must be between 0 and max score.');
        }
        if ($passMark < 0 || $passMark > $maxScore) {
            throw new \InvalidArgumentException('Pass mark must be between 0 and max score.');
        }

        $passed = $score >= $passMark ? 1 : 0;
        $stmt   = $this->db()->prepare(
            'INSERT INTO quiz_attempts (quiz, user_id, score, max_score, passed) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$quiz, $userId, $score, $maxScore, $passed]);

        return (int)$this->db()->lastInsertId();
    }

    /**
     * Best (highest) score a user achieved on a quiz, or null if no attempts.
     */
    public function bestScore(string $quiz, int $userId): ?int
    {
        $quiz = $this->validate($quiz);
        $stmt = $this->db()->prepare('SELECT MAX(score) FROM quiz_attempts WHERE quiz = ? AND user_id = ?');
        $stmt->execute([$quiz, $userId]);
        $best = $stmt->fetchColumn();

        return $best === null || $best === false ? null : (int)$best;
    }

    /**
     * Whether the user has passed the quiz in any attempt.
     */
    public function hasPassed(string $quiz, int $userId): bool
    {
        $quiz = $this->validate($quiz);
        $stmt = $this->db()->prepare(
            'SELECT 1 FROM quiz_attempts WHERE quiz = ? AND user_id = ? AND passed = 1 LIMIT 1'
        );
        $stmt->execute([$quiz, $userId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Number of attempts a user made on a quiz.
     */
    public function attemptCount(string $quiz, int $userId): int
    {
        $quiz = $this->validate($quiz);
        $stmt = $this->db()->prepare('SELECT COUNT(*) FROM quiz_attempts WHERE quiz = ? AND user_id = ?');
        $stmt->execute([$quiz, $userId]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * A user's attempts on a quiz, newest first.
     *
     * @return array<int,array{score:int,max_score:int,passed:bool}>
     */
    public function attempts(string $quiz, int $userId): array
    {
        $quiz = $this->validate($quiz);
        $stmt = $this->db()->prepare(
            'SELECT score, max_score, passed FROM quiz_attempts WHERE quiz = ? AND user_id = ? ORDER BY id DESC'
        );
        $stmt->execute([$quiz, $userId]);

        $out = [];
        /** @var array<string,mixed> $row */
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = [
                'score'     => (int)$row['score'],
                'max_score' => (int)$row['max_score'],
                'passed'    => (bool)$row['passed'],
            ];
        }

        return $out;
    }

    /**
     * Fraction of attempts on a quiz that passed (0.0–1.0), rounded to 4 dp.
     * Returns 0.0 when there are no attempts.
     */
    public function passRate(string $quiz): float
    {
        $quiz  = $this->validate($quiz);
        $stmt  = $this->db()->prepare('SELECT COUNT(*) AS total, COALESCE(SUM(passed), 0) AS passed FROM quiz_attempts WHERE quiz = ?');
        $stmt->execute([$quiz]);
        $row   = $stmt->fetch(PDO::FETCH_ASSOC);
        $total = (int)($row['total'] ?? 0);
        if ($total === 0) {
            return 0.0;
        }

        return round((int)$row['passed'] / $total, 4);
    }

    // ── private ───────────────────────────────────────────────────────────────

    private function validate(string $quiz): string
    {
        $quiz = trim($quiz);
        if ($quiz === '') {
            throw new \InvalidArgumentException('Quiz must not be empty.');
        }

        return $quiz;
    }

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
