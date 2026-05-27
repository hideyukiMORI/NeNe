<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * MultiLangContent — multilingual content strings for CMS-style translation.
 *
 * Stores translated text values keyed by a content key and locale. Useful for
 * CMS content, email templates, and UI label overrides that live in the
 * database rather than flat language files.
 *
 * Keys are normalised to lowercase. Locale codes are lowercased and validated
 * (e.g. 'en', 'ja', 'en-us'). A fallback locale can be used when the
 * requested locale has no value.
 *
 * ## Usage
 *
 * ```php
 * $ml = new MultiLangContent($pdo);
 *
 * $ml->set('welcome_title', 'en', 'Welcome!');
 * $ml->set('welcome_title', 'ja', 'ようこそ！');
 *
 * $ml->get('welcome_title', 'ja');             // 'ようこそ！'
 * $ml->get('welcome_title', 'fr', 'en');        // 'Welcome!' (fallback)
 * $ml->getAll('welcome_title');                 // ['en' => 'Welcome!', 'ja' => 'ようこそ！']
 * $ml->getLocales('welcome_title');             // ['en', 'ja']
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE multilang_content (
 *     id           INTEGER PRIMARY KEY AUTOINCREMENT,
 *     content_key  VARCHAR(255) NOT NULL,
 *     locale       VARCHAR(20)  NOT NULL,
 *     value        TEXT         NOT NULL,
 *     updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (content_key, locale)
 * );
 * ```
 */
final class MultiLangContent
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Set a translated value for a key + locale (upsert).
     *
     * @throws \InvalidArgumentException on empty key/locale/value.
     */
    public function set(string $key, string $locale, string $value): void
    {
        [$key, $locale] = $this->validateKeyLocale($key, $locale);
        $value          = trim($value);
        if ($value === '') {
            throw new \InvalidArgumentException('value must not be empty.');
        }

        $now    = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $driver = $this->db()->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $sql = 'INSERT INTO multilang_content (content_key, locale, value, updated_at)
                    VALUES (:key, :locale, :value, :now)
                    ON CONFLICT (content_key, locale) DO UPDATE SET value = :value2, updated_at = :now2';
            $this->db()->prepare($sql)->execute([
                ':key'    => $key,
                ':locale' => $locale,
                ':value'  => $value,
                ':now'    => $now,
                ':value2' => $value,
                ':now2'   => $now,
            ]);
        } else {
            $sql = 'INSERT INTO multilang_content (content_key, locale, value, updated_at)
                    VALUES (:key, :locale, :value, :now)
                    ON DUPLICATE KEY UPDATE value = :value2, updated_at = :now2';
            $this->db()->prepare($sql)->execute([
                ':key'    => $key,
                ':locale' => $locale,
                ':value'  => $value,
                ':now'    => $now,
                ':value2' => $value,
                ':now2'   => $now,
            ]);
        }
    }

    /**
     * Retrieve the value for a key + locale.
     *
     * Returns the fallback locale value when the primary locale has no entry.
     * Returns null if neither the primary nor fallback locale has a value.
     *
     * @param string $fallbackLocale Optional locale to fall back to.
     */
    public function get(string $key, string $locale, string $fallbackLocale = ''): ?string
    {
        [$key, $locale] = $this->validateKeyLocale($key, $locale);
        $value          = $this->fetch($key, $locale);
        if ($value !== null) {
            return $value;
        }
        if ($fallbackLocale !== '') {
            $fallbackLocale = mb_strtolower(trim($fallbackLocale));
            return $this->fetch($key, $fallbackLocale);
        }
        return null;
    }

    /**
     * Return all locale→value pairs for a key.
     *
     * @return array<string,string> locale => value
     */
    public function getAll(string $key): array
    {
        $key  = mb_strtolower(trim($key));
        $stmt = $this->db()->prepare(
            'SELECT locale, value FROM multilang_content WHERE content_key = :key ORDER BY locale ASC'
        );
        $stmt->execute([':key' => $key]);
        $rows   = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[(string)$row['locale']] = (string)$row['value'];
        }
        return $result;
    }

    /**
     * Return the list of locales that have a value for a key.
     *
     * @return list<string>
     */
    public function getLocales(string $key): array
    {
        $key  = mb_strtolower(trim($key));
        $stmt = $this->db()->prepare(
            'SELECT locale FROM multilang_content WHERE content_key = :key ORDER BY locale ASC'
        );
        $stmt->execute([':key' => $key]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    /**
     * Delete the value for a specific key + locale.
     *
     * @return bool True if found and deleted.
     */
    public function delete(string $key, string $locale): bool
    {
        [$key, $locale] = $this->validateKeyLocale($key, $locale);
        $stmt           = $this->db()->prepare(
            'DELETE FROM multilang_content WHERE content_key = :key AND locale = :locale'
        );
        $stmt->execute([':key' => $key, ':locale' => $locale]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Delete all translations for a key.
     *
     * @return int Number of rows deleted.
     */
    public function deleteKey(string $key): int
    {
        $key  = mb_strtolower(trim($key));
        $stmt = $this->db()->prepare('DELETE FROM multilang_content WHERE content_key = :key');
        $stmt->execute([':key' => $key]);
        return $stmt->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    private function fetch(string $key, string $locale): ?string
    {
        $stmt = $this->db()->prepare(
            'SELECT value FROM multilang_content WHERE content_key = :key AND locale = :locale'
        );
        $stmt->execute([':key' => $key, ':locale' => $locale]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? (string)$row['value'] : null;
    }

    /**
     * @return array{string, string}
     */
    private function validateKeyLocale(string $key, string $locale): array
    {
        $key    = mb_strtolower(trim($key));
        $locale = mb_strtolower(trim($locale));
        if ($key === '') {
            throw new \InvalidArgumentException('key must not be empty.');
        }
        if ($locale === '') {
            throw new \InvalidArgumentException('locale must not be empty.');
        }
        return [$key, $locale];
    }
}
