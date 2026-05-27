<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * SurveyResponse — store and query structured survey/quiz answers.
 *
 * Each response is keyed by (survey_id, user_id, question_key).
 * Supports re-submission (upsert), result aggregation, and per-user retrieval.
 *
 * ## Usage
 *
 * ```php
 * $sr = new SurveyResponse($pdo);
 *
 * // Submit answers
 * $sr->submit('survey-1', 'user-1', ['q1' => 'yes', 'q2' => 'no']);
 *
 * // Check if user completed the survey
 * $sr->hasResponded('survey-1', 'user-1'); // true
 *
 * // Get a user's answers
 * $answers = $sr->get('survey-1', 'user-1');
 *
 * // Count responses per survey
 * $sr->respondentCount('survey-1');
 *
 * // Aggregate answers for a question
 * $sr->tally('survey-1', 'q1'); // ['yes' => 5, 'no' => 3]
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE survey_responses (
 *     id            INTEGER PRIMARY KEY AUTOINCREMENT,
 *     survey_id     VARCHAR(255) NOT NULL,
 *     user_id       VARCHAR(255) NOT NULL,
 *     question_key  VARCHAR(255) NOT NULL,
 *     answer        TEXT         NOT NULL DEFAULT '',
 *     created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (survey_id, user_id, question_key)
 * );
 * ```
 */
final class SurveyResponse
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Submit (or update) a user's answers for a survey.
     *
     * @param  array<string,string> $answers  Map of question_key => answer.
     * @throws \InvalidArgumentException if survey_id, user_id, or answers is empty.
     */
    public function submit(string $surveyId, string $userId, array $answers): void
    {
        [$surveyId, $userId] = $this->normalise($surveyId, $userId);
        if (empty($answers)) {
            throw new \InvalidArgumentException('answers must not be empty.');
        }

        $db     = $this->db();
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);

        foreach ($answers as $key => $value) {
            $key   = trim((string)$key);
            $value = (string)$value;
            if ($key === '') {
                continue;
            }
            if ($driver === 'sqlite') {
                $db->prepare(
                    'INSERT INTO survey_responses (survey_id, user_id, question_key, answer)
                     VALUES (:sid, :uid, :key, :val)
                     ON CONFLICT (survey_id, user_id, question_key)
                     DO UPDATE SET answer = excluded.answer,
                                   updated_at = CURRENT_TIMESTAMP'
                )->execute([':sid' => $surveyId, ':uid' => $userId, ':key' => $key, ':val' => $value]);
            } else {
                $db->prepare(
                    'INSERT INTO survey_responses (survey_id, user_id, question_key, answer)
                     VALUES (:sid, :uid, :key, :val)
                     ON DUPLICATE KEY UPDATE answer = VALUES(answer),
                                             updated_at = CURRENT_TIMESTAMP'
                )->execute([':sid' => $surveyId, ':uid' => $userId, ':key' => $key, ':val' => $value]);
            }
        }
    }

    /**
     * Check whether a user has submitted at least one answer for a survey.
     */
    public function hasResponded(string $surveyId, string $userId): bool
    {
        [$surveyId, $userId] = $this->normalise($surveyId, $userId);
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM survey_responses
             WHERE survey_id = :sid AND user_id = :uid'
        );
        $stmt->execute([':sid' => $surveyId, ':uid' => $userId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Get all answers submitted by a user for a survey.
     *
     * @return array<string,string>  Map of question_key => answer.
     */
    public function get(string $surveyId, string $userId): array
    {
        [$surveyId, $userId] = $this->normalise($surveyId, $userId);
        $stmt = $this->db()->prepare(
            'SELECT question_key, answer FROM survey_responses
             WHERE survey_id = :sid AND user_id = :uid
             ORDER BY id ASC'
        );
        $stmt->execute([':sid' => $surveyId, ':uid' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $out  = [];
        foreach ($rows as $row) {
            $out[$row['question_key']] = $row['answer'];
        }
        return $out;
    }

    /**
     * Get the answer for a specific question from a user.
     *
     * @return string|null  null if not answered.
     */
    public function getAnswer(string $surveyId, string $userId, string $questionKey): ?string
    {
        [$surveyId, $userId] = $this->normalise($surveyId, $userId);
        $questionKey = trim($questionKey);
        $stmt = $this->db()->prepare(
            'SELECT answer FROM survey_responses
             WHERE survey_id = :sid AND user_id = :uid AND question_key = :key LIMIT 1'
        );
        $stmt->execute([':sid' => $surveyId, ':uid' => $userId, ':key' => $questionKey]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (string)$val : null;
    }

    /**
     * Count distinct users who responded to a survey.
     */
    public function respondentCount(string $surveyId): int
    {
        $surveyId = trim($surveyId);
        $stmt = $this->db()->prepare(
            'SELECT COUNT(DISTINCT user_id) FROM survey_responses WHERE survey_id = :sid'
        );
        $stmt->execute([':sid' => $surveyId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Tally all answers for a given question across all respondents.
     *
     * @return array<string,int>  Map of answer => count, sorted by count desc.
     */
    public function tally(string $surveyId, string $questionKey): array
    {
        $surveyId    = trim($surveyId);
        $questionKey = trim($questionKey);
        $stmt = $this->db()->prepare(
            'SELECT answer, COUNT(*) AS cnt FROM survey_responses
             WHERE survey_id = :sid AND question_key = :key
             GROUP BY answer
             ORDER BY cnt DESC'
        );
        $stmt->execute([':sid' => $surveyId, ':key' => $questionKey]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $out  = [];
        foreach ($rows as $row) {
            $out[$row['answer']] = (int)$row['cnt'];
        }
        return $out;
    }

    /**
     * Delete all responses from a user for a survey.
     *
     * @return bool True if any rows were deleted.
     */
    public function deleteUser(string $surveyId, string $userId): bool
    {
        [$surveyId, $userId] = $this->normalise($surveyId, $userId);
        $stmt = $this->db()->prepare(
            'DELETE FROM survey_responses WHERE survey_id = :sid AND user_id = :uid'
        );
        $stmt->execute([':sid' => $surveyId, ':uid' => $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Delete all responses for an entire survey.
     *
     * @return int Number of rows deleted.
     */
    public function deleteSurvey(string $surveyId): int
    {
        $surveyId = trim($surveyId);
        $stmt = $this->db()->prepare(
            'DELETE FROM survey_responses WHERE survey_id = :sid'
        );
        $stmt->execute([':sid' => $surveyId]);
        return $stmt->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    /**
     * @return array{string, string}
     */
    private function normalise(string $surveyId, string $userId): array
    {
        $surveyId = trim($surveyId);
        $userId   = trim($userId);
        if ($surveyId === '') {
            throw new \InvalidArgumentException('survey_id must not be empty.');
        }
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }
        return [$surveyId, $userId];
    }
}
