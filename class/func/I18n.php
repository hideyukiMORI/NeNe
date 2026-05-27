<?php

/**
 * AYANE : ayane.co.jp
 * powered by NENE.
 *
 * PHP Version >= 8.4
 *
 * @package   AYANE
 * @author    hideyukiMORI <info@ayane.co.jp>
 * @copyright 2021 AYANE
 * @license   https://opensource.org/licenses/MIT MIT License
 * @link      https://ayane.co.jp/
 */

declare(strict_types=1);

namespace Nene\Func;

/**
 * Lightweight i18n message-catalog helper.
 *
 * Provides a static registry of locale → key → message string mappings,
 * with `{placeholder}` substitution and locale fallback.
 *
 * ## Basic usage
 *
 * ```php
 * I18n::setLocale('ja');
 * I18n::load('ja', [
 *     'greeting' => 'こんにちは、{name}さん！',
 *     'items'    => '{count}件のアイテムがあります',
 * ]);
 * I18n::load('en', [
 *     'greeting' => 'Hello, {name}!',
 *     'items'    => 'You have {count} item(s)',
 * ]);
 *
 * echo I18n::t('greeting', ['name' => '太郎']);  // こんにちは、太郎さん！
 * echo I18n::t('greeting', ['name' => 'Taro'], 'en');  // Hello, Taro!
 * echo I18n::t('unknown.key');  // 'unknown.key' (key returned as-is)
 * ```
 *
 * ## Placeholder syntax
 *
 * Placeholders are written as `{key}`. The `$params` array is substituted
 * by matching keys. Unknown placeholders are left as-is.
 *
 * ## Fallback chain
 *
 * 1. Requested locale (or default locale when no locale given).
 * 2. Default locale (if different from requested).
 * 3. The key string itself.
 */
final class I18n
{
    /**
     * Active default locale (e.g. 'en', 'ja').
     *
     * @var string
     */
    private static string $defaultLocale = 'en';

    /**
     * Message catalogs keyed by locale.
     *
     * @var array<string, array<string, string>>
     */
    private static array $catalogs = [];

    /**
     * Set the default locale used when no locale is passed to t().
     */
    public static function setLocale(string $locale): void
    {
        self::$defaultLocale = $locale;
    }

    /**
     * Get the current default locale.
     */
    public static function locale(): string
    {
        return self::$defaultLocale;
    }

    /**
     * Register (or merge) a message catalog for the given locale.
     *
     * Calling load() twice with the same locale merges the arrays; later keys
     * win.
     *
     * @param string               $locale   Locale identifier (e.g. 'en', 'ja').
     * @param array<string,string> $messages Key → message string map.
     */
    public static function load(string $locale, array $messages): void
    {
        self::$catalogs[$locale] = array_merge(
            self::$catalogs[$locale] ?? [],
            $messages
        );
    }

    /**
     * Translate a key with optional placeholder substitution.
     *
     * Looks up `$key` in:
     * 1. `$locale` (or the default locale when empty string is passed).
     * 2. The default locale, if different from the requested one.
     * 3. Falls back to the key itself when no match is found.
     *
     * Placeholders are written as `{name}` and replaced with `$params['name']`.
     * Unknown placeholders are left as-is.
     *
     * @param string               $key    Message key.
     * @param array<string,string> $params Placeholder values.
     * @param string               $locale Locale override; uses default locale when empty.
     *
     * @return string Translated and substituted string, or $key if not found.
     */
    public static function t(string $key, array $params = [], string $locale = ''): string
    {
        $resolved = $locale !== '' ? $locale : self::$defaultLocale;

        // 1. Try requested locale
        $message = self::$catalogs[$resolved][$key] ?? null;

        // 2. Fallback to default locale if different
        if ($message === null && $resolved !== self::$defaultLocale) {
            $message = self::$catalogs[self::$defaultLocale][$key] ?? null;
        }

        // 3. Key itself as last resort
        if ($message === null) {
            return $key;
        }

        return self::substitute($message, $params);
    }

    /**
     * Replace `{key}` placeholders in a message string.
     *
     * @param string               $message Raw message with `{key}` placeholders.
     * @param array<string,string> $params  Replacement values.
     */
    private static function substitute(string $message, array $params): string
    {
        if ($params === []) {
            return $message;
        }

        return preg_replace_callback(
            '/\{([^}]+)\}/',
            static function (array $m) use ($params): string {
                return $params[$m[1]] ?? $m[0];
            },
            $message
        ) ?? $message;
    }

    /**
     * Reset all catalogs and restore the default locale.
     *
     * Intended for use in tests — call in tearDown() to isolate test state.
     */
    public static function reset(): void
    {
        self::$defaultLocale = 'en';
        self::$catalogs      = [];
    }
}
