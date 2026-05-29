<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * DB-backed general-purpose text templates with variable substitution.
 *
 * Distinct from EmailTemplate (which has a subject, HTML body, and locale
 * routing), TextTemplate is a lightweight body-only store for SMS messages,
 * push notification bodies, Slack alerts, CLI output, or any other text
 * surface where you only need a single text block per channel+locale.
 *
 * Variables are enclosed in double curly braces: {{variable_name}}.
 *
 * ## Usage
 *
 * ```php
 * $tt = new TextTemplate($pdo);
 *
 * // Store a template
 * $tt->set('welcome_sms', 'sms', 'Hi {{name}}, your code is {{code}}.');
 *
 * // Render it
 * $text = $tt->render('welcome_sms', 'sms', ['name' => 'Alice', 'code' => '1234']);
 * // → 'Hi Alice, your code is 1234.'
 *
 * // Locale fallback: if 'ja' not found, falls back to 'en'
 * $tt->set('welcome_sms', 'sms', 'こんにちは {{name}}！', 'ja');
 * $text = $tt->render('welcome_sms', 'sms', ['name' => '田中'], 'ja');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE text_templates (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     tpl_key     VARCHAR(100) NOT NULL,
 *     channel     VARCHAR(50)  NOT NULL,
 *     locale      CHAR(5)      NOT NULL DEFAULT 'en',
 *     body        TEXT         NOT NULL,
 *     active      TINYINT(1)   NOT NULL DEFAULT 1,
 *     created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (tpl_key, channel, locale)
 * );
 * ```
 */
final class TextTemplate
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── private helpers ───────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    // ── set ───────────────────────────────────────────────────────────────────

    /**
     * Insert or update a template body (cross-driver upsert).
     *
     * @throws \InvalidArgumentException if key, channel, or body are empty.
     */
    public function set(
        string $key,
        string $channel,
        string $body,
        string $locale = 'en',
    ): void {
        if ($key === '') {
            throw new \InvalidArgumentException('key must not be empty.');
        }

        if ($channel === '') {
            throw new \InvalidArgumentException('channel must not be empty.');
        }

        if ($body === '') {
            throw new \InvalidArgumentException('body must not be empty.');
        }

        $driver = $this->db()->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'mysql') {
            $stmt = $this->db()->prepare(
                'INSERT INTO text_templates (tpl_key, channel, locale, body)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE body = VALUES(body), updated_at = CURRENT_TIMESTAMP'
            );
        } else {
            $stmt = $this->db()->prepare(
                'INSERT INTO text_templates (tpl_key, channel, locale, body)
                 VALUES (?, ?, ?, ?)
                 ON CONFLICT (tpl_key, channel, locale)
                 DO UPDATE SET body = excluded.body, updated_at = CURRENT_TIMESTAMP'
            );
        }

        $stmt->execute([$key, $channel, $locale, $body]);
    }

    // ── get ───────────────────────────────────────────────────────────────────

    /**
     * Retrieve a template row with locale fallback to 'en'.
     *
     * Returns null if the key+channel combination does not exist even in 'en'.
     *
     * @return array<string,mixed>|null
     */
    public function get(string $key, string $channel, string $locale = 'en'): ?array
    {
        if ($locale !== 'en') {
            $stmt = $this->db()->prepare(
                'SELECT * FROM text_templates
                 WHERE tpl_key = ? AND channel = ? AND locale = ? AND active = 1'
            );
            $stmt->execute([$key, $channel, $locale]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row !== false) {
                return $row;
            }
        }

        // fallback to 'en'
        $stmt = $this->db()->prepare(
            'SELECT * FROM text_templates
             WHERE tpl_key = ? AND channel = ? AND locale = ? AND active = 1'
        );
        $stmt->execute([$key, $channel, 'en']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    // ── render ────────────────────────────────────────────────────────────────

    /**
     * Render a template by replacing {{variable}} placeholders with values.
     *
     * @param  array<string,string|int|float> $vars Variable→value map.
     * @return string                               Rendered text.
     * @throws \RuntimeException if the template does not exist.
     */
    public function render(
        string $key,
        string $channel,
        array $vars = [],
        string $locale = 'en',
    ): string {
        $row = $this->get($key, $channel, $locale);

        if ($row === null) {
            throw new \RuntimeException(
                "TextTemplate '{$key}' for channel '{$channel}' not found."
            );
        }

        $body = (string)$row['body'];

        foreach ($vars as $varName => $value) {
            $body = str_replace('{{' . $varName . '}}', (string)$value, $body);
        }

        return $body;
    }

    // ── activate / deactivate ─────────────────────────────────────────────────

    /**
     * Activate a template (makes it available via get/render).
     */
    public function activate(string $key, string $channel, string $locale = 'en'): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE text_templates
             SET active = 1, updated_at = CURRENT_TIMESTAMP
             WHERE tpl_key = ? AND channel = ? AND locale = ?'
        );
        $stmt->execute([$key, $channel, $locale]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Deactivate a template without deleting it.
     */
    public function deactivate(string $key, string $channel, string $locale = 'en'): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE text_templates
             SET active = 0, updated_at = CURRENT_TIMESTAMP
             WHERE tpl_key = ? AND channel = ? AND locale = ?'
        );
        $stmt->execute([$key, $channel, $locale]);
        return $stmt->rowCount() > 0;
    }

    // ── remove ────────────────────────────────────────────────────────────────

    /**
     * Delete templates for a key+channel combination.
     *
     * If $locale is provided, only that locale is removed.
     * If $locale is null, all locales for key+channel are removed.
     *
     * @return int Number of rows deleted.
     */
    public function remove(string $key, string $channel, ?string $locale = null): int
    {
        if ($locale !== null) {
            $stmt = $this->db()->prepare(
                'DELETE FROM text_templates WHERE tpl_key = ? AND channel = ? AND locale = ?'
            );
            $stmt->execute([$key, $channel, $locale]);
        } else {
            $stmt = $this->db()->prepare(
                'DELETE FROM text_templates WHERE tpl_key = ? AND channel = ?'
            );
            $stmt->execute([$key, $channel]);
        }

        return (int)$stmt->rowCount();
    }
}
