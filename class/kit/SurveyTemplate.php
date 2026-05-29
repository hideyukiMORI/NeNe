<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * SurveyTemplate — reusable form/survey template definitions.
 *
 * Stores reusable survey/form templates with structured question definitions.
 * Distinct from SurveyResponse which records the answers; SurveyTemplate
 * defines what questions exist and their display order and types.
 *
 * Templates can be activated/deactivated. Questions are ordered by position.
 *
 * ## Usage
 *
 * ```php
 * $st = new SurveyTemplate($pdo);
 *
 * $id = $st->create('NPS Survey', 'Monthly Net Promoter Score');
 * $st->addQuestion($id, 'score',   'How likely to recommend us? (0-10)', 'number',   1, true);
 * $st->addQuestion($id, 'comment', 'Any comments?',                       'textarea', 2, false);
 * $st->activate($id);
 *
 * $template  = $st->get($id);
 * $questions = $st->questions($id);
 * $active    = $st->listActive();
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE survey_templates (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     name        VARCHAR(255) NOT NULL,
 *     description TEXT         NULL,
 *     active      TINYINT(1)   NOT NULL DEFAULT 0,
 *     created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * CREATE TABLE survey_template_questions (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     template_id INTEGER      NOT NULL,
 *     question_key VARCHAR(100) NOT NULL,
 *     label       TEXT         NOT NULL,
 *     type        VARCHAR(50)  NOT NULL DEFAULT 'text',
 *     position    INTEGER      NOT NULL DEFAULT 0,
 *     required    TINYINT(1)   NOT NULL DEFAULT 0
 * );
 * ```
 */
final class SurveyTemplate
{
    public const TYPE_TEXT     = 'text';
    public const TYPE_TEXTAREA = 'textarea';
    public const TYPE_NUMBER   = 'number';
    public const TYPE_SELECT   = 'select';
    public const TYPE_RADIO    = 'radio';
    public const TYPE_CHECKBOX = 'checkbox';

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Create a new survey template.
     *
     * @return int New template ID.
     * @throws \InvalidArgumentException on empty name.
     */
    public function create(string $name, ?string $description = null): int
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('name must not be empty.');
        }

        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'INSERT INTO survey_templates (name, description, active, created_at)
             VALUES (:name, :desc, 0, :now)'
        );
        $stmt->execute([':name' => $name, ':desc' => $description, ':now' => $now]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Retrieve a template row.
     *
     * @return array<string,mixed>|null
     */
    public function get(int $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM survey_templates WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Return all active templates.
     *
     * @return list<array<string,mixed>>
     */
    public function listActive(): array
    {
        $stmt = $this->db()->query('SELECT * FROM survey_templates WHERE active = 1 ORDER BY name ASC');
        if ($stmt === false) {
            return [];
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Activate a template.
     *
     * @return bool True if found and activated.
     */
    public function activate(int $id): bool
    {
        $stmt = $this->db()->prepare('UPDATE survey_templates SET active = 1 WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Deactivate a template.
     *
     * @return bool True if found and deactivated.
     */
    public function deactivate(int $id): bool
    {
        $stmt = $this->db()->prepare('UPDATE survey_templates SET active = 0 WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Add a question to a template.
     *
     * @return int New question ID.
     * @throws \InvalidArgumentException on empty key/label.
     */
    public function addQuestion(
        int $templateId,
        string $key,
        string $label,
        string $type = self::TYPE_TEXT,
        int $position = 0,
        bool $required = false
    ): int {
        $key   = trim($key);
        $label = trim($label);
        if ($key === '') {
            throw new \InvalidArgumentException('question_key must not be empty.');
        }
        if ($label === '') {
            throw new \InvalidArgumentException('label must not be empty.');
        }

        $stmt = $this->db()->prepare(
            'INSERT INTO survey_template_questions (template_id, question_key, label, type, position, required)
             VALUES (:tid, :key, :label, :type, :pos, :req)'
        );
        $stmt->execute([
            ':tid'   => $templateId,
            ':key'   => $key,
            ':label' => $label,
            ':type'  => $type,
            ':pos'   => $position,
            ':req'   => $required ? 1 : 0,
        ]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Return all questions for a template, ordered by position.
     *
     * @return list<array<string,mixed>>
     */
    public function questions(int $templateId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, template_id, question_key, label, type, position, required
             FROM survey_template_questions
             WHERE template_id = :tid
             ORDER BY position ASC, id ASC'
        );
        $stmt->execute([':tid' => $templateId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Remove a single question.
     *
     * @return bool True if found and deleted.
     */
    public function removeQuestion(int $questionId): bool
    {
        $stmt = $this->db()->prepare('DELETE FROM survey_template_questions WHERE id = :id');
        $stmt->execute([':id' => $questionId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Delete a template and all its questions.
     *
     * @return bool True if the template was found and deleted.
     */
    public function delete(int $id): bool
    {
        $db = $this->db();
        $db->prepare('DELETE FROM survey_template_questions WHERE template_id = :id')->execute([':id' => $id]);
        $stmt = $db->prepare('DELETE FROM survey_templates WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
