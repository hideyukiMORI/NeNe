<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\DbUpsert;
use Nene\Xion\PdoConnection;
use PDO;

/**
 * RedactionRule — configurable PII/secret masking rules for text.
 *
 * Holds a registry of named regular-expression rules and applies them to mask
 * sensitive data (card numbers, emails, tokens) before text is logged, shown
 * to support staff, or exported. Rules are applied in priority order so more
 * specific patterns can run first.
 *
 * Patterns are **operator-supplied** PCRE regular expressions (including
 * delimiters, e.g. `'/\b\d{13,16}\b/'`), validated for compilability when
 * added — they are configuration, never end-user input.
 *
 * ## Usage
 *
 * ```php
 * $r = new RedactionRule($pdo);
 *
 * $r->addRule('card', '/\b\d{13,16}\b/', '[CARD]', priority: 10);
 * $r->addRule('email', '/[\w.+-]+@[\w-]+\.[\w.-]+/', '[EMAIL]');
 *
 * $r->redact('pay 4111111111111111 or mail a@b.com');
 * // 'pay [CARD] or mail [EMAIL]'
 *
 * $r->disable('email');
 * $r->redact('mail a@b.com'); // unchanged — rule disabled
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE redaction_rules (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     name        VARCHAR(100) NOT NULL,
 *     pattern     TEXT         NOT NULL,
 *     replacement VARCHAR(255) NOT NULL DEFAULT '[REDACTED]',
 *     priority    INTEGER      NOT NULL DEFAULT 0,
 *     enabled     INTEGER      NOT NULL DEFAULT 1,
 *     created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (name)
 * );
 * ```
 */
final class RedactionRule
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Add or update a redaction rule. Idempotent per name.
     *
     * @param  string $name        Rule name.
     * @param  string $pattern     PCRE pattern with delimiters (must compile).
     * @param  string $replacement Replacement string.
     * @param  int    $priority    Higher runs first (default 0).
     * @throws \InvalidArgumentException on empty name or invalid pattern.
     */
    public function addRule(string $name, string $pattern, string $replacement = '[REDACTED]', int $priority = 0): void
    {
        $name = $this->validateName($name);
        $this->assertValidPattern($pattern);

        DbUpsert::run(
            $this->db(),
            table:        'redaction_rules',
            data:         [
                'name'        => $name,
                'pattern'     => $pattern,
                'replacement' => $replacement,
                'priority'    => $priority,
                'enabled'     => 1,
            ],
            conflictCols: ['name'],
            updateCols:   ['pattern', 'replacement', 'priority', 'enabled'],
        );
    }

    /**
     * Apply all enabled rules to a string, in priority (then name) order.
     *
     * @param  string $text Input text.
     * @return string       Redacted text.
     */
    public function redact(string $text): string
    {
        foreach ($this->enabledRules() as $rule) {
            $result = preg_replace($rule['pattern'], $rule['replacement'], $text);
            if ($result !== null) {
                $text = $result;
            }
        }

        return $text;
    }

    /**
     * Apply a single named rule (ignores enabled flag). Returns text unchanged
     * if the rule does not exist.
     */
    public function applyRule(string $name, string $text): string
    {
        $name = $this->validateName($name);
        $stmt = $this->db()->prepare('SELECT pattern, replacement FROM redaction_rules WHERE name = ?');
        $stmt->execute([$name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return $text;
        }

        $result = preg_replace((string)$row['pattern'], (string)$row['replacement'], $text);

        return $result ?? $text;
    }

    /**
     * Enable a rule. No-op if absent.
     */
    public function enable(string $name): void
    {
        $this->setEnabled($name, true);
    }

    /**
     * Disable a rule without deleting it. No-op if absent.
     */
    public function disable(string $name): void
    {
        $this->setEnabled($name, false);
    }

    /**
     * Remove a rule. No-op if absent.
     */
    public function remove(string $name): void
    {
        $name = $this->validateName($name);
        $stmt = $this->db()->prepare('DELETE FROM redaction_rules WHERE name = ?');
        $stmt->execute([$name]);
    }

    /**
     * List all rules in application order (priority desc, name asc).
     *
     * @return array<int,array{name:string,pattern:string,replacement:string,priority:int,enabled:bool}>
     */
    public function rules(): array
    {
        $stmt = $this->db()->query(
            'SELECT name, pattern, replacement, priority, enabled FROM redaction_rules
             ORDER BY priority DESC, name ASC'
        );
        $rows = $stmt === false ? [] : $stmt->fetchAll(PDO::FETCH_ASSOC);

        $out = [];
        /** @var array<string,mixed> $row */
        foreach ($rows as $row) {
            $out[] = [
                'name'        => (string)$row['name'],
                'pattern'     => (string)$row['pattern'],
                'replacement' => (string)$row['replacement'],
                'priority'    => (int)$row['priority'],
                'enabled'     => (bool)$row['enabled'],
            ];
        }

        return $out;
    }

    // ── private ───────────────────────────────────────────────────────────────

    /**
     * @return array<int,array{pattern:string,replacement:string}>
     */
    private function enabledRules(): array
    {
        $stmt = $this->db()->query(
            'SELECT pattern, replacement FROM redaction_rules WHERE enabled = 1
             ORDER BY priority DESC, name ASC'
        );
        $rows = $stmt === false ? [] : $stmt->fetchAll(PDO::FETCH_ASSOC);

        $out = [];
        /** @var array<string,mixed> $row */
        foreach ($rows as $row) {
            $out[] = ['pattern' => (string)$row['pattern'], 'replacement' => (string)$row['replacement']];
        }

        return $out;
    }

    private function setEnabled(string $name, bool $enabled): void
    {
        $name = $this->validateName($name);
        $stmt = $this->db()->prepare('UPDATE redaction_rules SET enabled = ? WHERE name = ?');
        $stmt->execute([$enabled ? 1 : 0, $name]);
    }

    private function assertValidPattern(string $pattern): void
    {
        $subject = '';
        if (@preg_match($pattern, $subject) === false) {
            throw new \InvalidArgumentException('Invalid regular expression pattern.');
        }
    }

    private function validateName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Name must not be empty.');
        }

        return $name;
    }

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
