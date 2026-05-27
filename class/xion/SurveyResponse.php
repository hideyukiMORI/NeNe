<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * SurveyResponse — form/survey response collection and analysis.
 *
 * Stores form submissions (survey responses) as named answer sets. Each
 * response belongs to a named survey and optionally a respondent. Individual
 * answers are stored in a child table. Aggregate summaries (answer frequency)
 * are available for result analysis.
 *
 * ## Usage
 *
 * ```php
 * $sr = new SurveyResponse($pdo);
 *
 * // Submit a response
 * $id = $sr->submit('nps-2026-q2', 'user-42', [
 *     'score'   => '9',
 *     'comment' => 'Great product!',
 * ]);
 *
 * // Query
 * $response  = $sr->find($id);
 * $answers   = $sr->answers($id);
 * $all       = $sr->forSurvey('nps-2026-q2', 50, 0);
 * $count     = $sr->countForSurvey('nps-2026-q2');
 * $frequency = $sr->answerFrequency('nps-2026-q2', 'score');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE survey_responses (
 *     id           INTEGER PRIMARY KEY AUTOINCREMENT,
 *     survey_name  VARCHAR(100) NOT NULL,
 *     respondent   VARCHAR(255) NULL,
 *     submitted_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * CREATE TABLE survey_answers (
 *     id           INTEGER PRIMARY KEY AUTOINCREMENT,
 *     response_id  INTEGER      NOT NULL,
 *     question_key VARCHAR(100) NOT NULL,
 *     answer       TEXT         NOT NULL
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
     * Submit a survey response with answers.
     *
     * @param array<string,string> $answers  question_key => answer value.
     * @return int Row ID of the new response.
     * @throws \InvalidArgumentException on empty surveyName or empty answers map.
     */
    public function submit(string $surveyName, ?string $respondent, array $answers): int
    {
        $surveyName = trim($surveyName);
        if ($surveyName === '') {
            throw new \InvalidArgumentException('surveyName must not be empty.');
        }
        if ($answers === []) {
            throw new \InvalidArgumentException('answers must not be empty.');
        }

        // Insert response header
        $stmt = $this->db()->prepare(
            'INSERT INTO survey_responses (survey_name, respondent) VALUES (:name, :resp)'
        );
        $stmt->execute([':name' => $surveyName, ':resp' => $respondent]);
        $responseId = (int)$this->db()->lastInsertId();

        // Insert individual answers
        $aStmt = $this->db()->prepare(
            'INSERT INTO survey_answers (response_id, question_key, answer)
             VALUES (:rid, :key, :ans)'
        );
        foreach ($answers as $key => $value) {
            $aStmt->execute([':rid' => $responseId, ':key' => (string)$key, ':ans' => (string)$value]);
        }

        return $responseId;
    }

    /**
     * Find a response header by ID.
     *
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM survey_responses WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Delete a response and all its answers.
     *
     * @return bool True if found and deleted.
     */
    public function delete(int $id): bool
    {
        // Delete answers first
        $stmt = $this->db()->prepare('DELETE FROM survey_answers WHERE response_id = :id');
        $stmt->execute([':id' => $id]);

        $stmt2 = $this->db()->prepare('DELETE FROM survey_responses WHERE id = :id');
        $stmt2->execute([':id' => $id]);
        return $stmt2->rowCount() > 0;
    }

    /**
     * Get all answers for a response as question_key => answer array.
     *
     * @return array<string,string>
     */
    public function answers(int $responseId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT question_key, answer FROM survey_answers WHERE response_id = :rid ORDER BY id ASC'
        );
        $stmt->execute([':rid' => $responseId]);
        $rows   = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $result = [];
        foreach ($rows as $row) {
            $result[(string)$row['question_key']] = (string)$row['answer'];
        }
        return $result;
    }

    /**
     * List response headers for a survey (newest first).
     *
     * @return list<array<string,mixed>>
     */
    public function forSurvey(string $surveyName, int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM survey_responses
             WHERE survey_name = :name
             ORDER BY id DESC
             LIMIT :lim OFFSET :off'
        );
        $stmt->bindValue(':name', trim($surveyName));
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Count total responses for a survey.
     */
    public function countForSurvey(string $surveyName): int
    {
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM survey_responses WHERE survey_name = :name'
        );
        $stmt->execute([':name' => trim($surveyName)]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Return answer frequency for a question across a survey.
     * Result: answer_value => count, sorted by count desc.
     *
     * @return array<string,int>
     */
    public function answerFrequency(string $surveyName, string $questionKey): array
    {
        $stmt = $this->db()->prepare(
            'SELECT a.answer, COUNT(*) AS cnt
             FROM survey_answers a
             JOIN survey_responses r ON r.id = a.response_id
             WHERE r.survey_name = :name AND a.question_key = :key
             GROUP BY a.answer
             ORDER BY cnt DESC, a.answer ASC'
        );
        $stmt->execute([':name' => trim($surveyName), ':key' => trim($questionKey)]);
        $rows   = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $result = [];
        foreach ($rows as $row) {
            $result[(string)$row['answer']] = (int)$row['cnt'];
        }
        return $result;
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
