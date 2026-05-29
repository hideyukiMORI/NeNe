<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * EmailTemplate — DB-stored email templates with variable substitution.
 *
 * Stores named email templates (subject + body) and renders them by replacing
 * `{{variable}}` placeholders with provided values. Supports multiple locales
 * for the same template name.
 *
 * ## Usage
 *
 * ```php
 * $et = new EmailTemplate($pdo);
 *
 * // Store a template
 * $et->save('welcome', 'Welcome, {{name}}!', 'Hi {{name}}, your account is ready.');
 * $et->save('welcome', 'Bienvenue, {{name}} !', 'Bonjour {{name}}, votre compte est prêt.', 'fr');
 *
 * // Render
 * [$subject, $body] = $et->render('welcome', ['name' => 'Alice']);
 * [$subject, $body] = $et->render('welcome', ['name' => 'Alice'], 'fr');
 *
 * // List
 * $et->all();
 * $et->find('welcome');
 * $et->delete('welcome', 'fr');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE email_templates (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     name       VARCHAR(100) NOT NULL,
 *     locale     VARCHAR(10)  NOT NULL DEFAULT 'en',
 *     subject    TEXT         NOT NULL DEFAULT '',
 *     body       TEXT         NOT NULL DEFAULT '',
 *     updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (name, locale)
 * );
 * ```
 */
final class EmailTemplate
{
    /** Placeholder pattern: {{variable_name}} */
    private const PLACEHOLDER = '/\{\{([a-zA-Z0-9_]+)\}\}/';

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Save (create or update) an email template.
     *
     * @throws \InvalidArgumentException if name is empty.
     */
    public function save(string $name, string $subject, string $body, string $locale = 'en'): void
    {
        $name   = $this->validateName($name);
        $locale = $locale === '' ? 'en' : trim($locale);
        $db     = $this->db();

        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $db->prepare(
                'INSERT INTO email_templates (name, locale, subject, body)
                 VALUES (:name, :locale, :subject, :body)
                 ON CONFLICT (name, locale)
                 DO UPDATE SET subject = excluded.subject,
                               body    = excluded.body,
                               updated_at = CURRENT_TIMESTAMP'
            )->execute([':name' => $name, ':locale' => $locale, ':subject' => $subject, ':body' => $body]);
        } else {
            $db->prepare(
                'INSERT INTO email_templates (name, locale, subject, body)
                 VALUES (:name, :locale, :subject, :body)
                 ON DUPLICATE KEY UPDATE subject = VALUES(subject),
                                         body    = VALUES(body),
                                         updated_at = CURRENT_TIMESTAMP'
            )->execute([':name' => $name, ':locale' => $locale, ':subject' => $subject, ':body' => $body]);
        }
    }

    /**
     * Retrieve a template record.
     *
     * @return array<string,mixed>|null
     */
    public function find(string $name, string $locale = 'en'): ?array
    {
        $name   = $this->validateName($name);
        $locale = $locale === '' ? 'en' : trim($locale);
        $stmt   = $this->db()->prepare(
            'SELECT id, name, locale, subject, body, updated_at
             FROM email_templates WHERE name = :name AND locale = :locale LIMIT 1'
        );
        $stmt->execute([':name' => $name, ':locale' => $locale]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Render a template by substituting `{{variable}}` placeholders.
     *
     * Falls back to the 'en' locale if the requested locale is not found.
     *
     * @param  array<string,string> $variables  Placeholder values keyed by variable name.
     * @return array{string, string}            [renderedSubject, renderedBody]
     * @throws \RuntimeException if the template does not exist in any locale.
     */
    public function render(string $name, array $variables = [], string $locale = 'en'): array
    {
        $tpl = $this->find($name, $locale);
        if ($tpl === null && $locale !== 'en') {
            $tpl = $this->find($name, 'en');
        }
        if ($tpl === null) {
            throw new \RuntimeException("Email template '{$name}' not found.");
        }

        $subject = $this->substitute((string)$tpl['subject'], $variables);
        $body    = $this->substitute((string)$tpl['body'], $variables);
        return [$subject, $body];
    }

    /**
     * List all templates (all names and locales).
     *
     * @return list<array<string,mixed>>
     */
    public function all(): array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, name, locale, subject, updated_at
             FROM email_templates ORDER BY name ASC, locale ASC'
        );
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Delete a template (specific locale, or all locales if locale is empty).
     *
     * @return int Number of rows deleted.
     */
    public function delete(string $name, string $locale = ''): int
    {
        $name = $this->validateName($name);
        if ($locale !== '') {
            $stmt = $this->db()->prepare(
                'DELETE FROM email_templates WHERE name = :name AND locale = :locale'
            );
            $stmt->execute([':name' => $name, ':locale' => $locale]);
        } else {
            $stmt = $this->db()->prepare(
                'DELETE FROM email_templates WHERE name = :name'
            );
            $stmt->execute([':name' => $name]);
        }
        return $stmt->rowCount();
    }

    /**
     * Return the variable names found in a template's subject and body.
     *
     * @return list<string>
     */
    public function variables(string $name, string $locale = 'en'): array
    {
        $tpl = $this->find($name, $locale);
        if ($tpl === null) {
            return [];
        }
        $text = (string)$tpl['subject'] . ' ' . (string)$tpl['body'];
        preg_match_all(self::PLACEHOLDER, $text, $matches);
        return array_values(array_unique($matches[1]));
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    private function validateName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Template name must not be empty.');
        }
        return $name;
    }

    /**
     * @param array<string,string> $variables
     */
    private function substitute(string $text, array $variables): string
    {
        return preg_replace_callback(
            self::PLACEHOLDER,
            static fn (array $m) => array_key_exists($m[1], $variables) ? (string)$variables[$m[1]] : $m[0],
            $text
        ) ?? $text;
    }
}
