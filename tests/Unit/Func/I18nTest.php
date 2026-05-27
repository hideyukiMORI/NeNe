<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Func;

use Nene\Func\I18n;
use PHPUnit\Framework\TestCase;

final class I18nTest extends TestCase
{
    protected function tearDown(): void
    {
        I18n::reset();
    }

    // --- setLocale / locale ---

    public function testDefaultLocaleIsEn(): void
    {
        self::assertSame('en', I18n::locale());
    }

    public function testSetLocaleChangesDefault(): void
    {
        I18n::setLocale('ja');
        self::assertSame('ja', I18n::locale());
    }

    // --- load / t: basic lookup ---

    public function testTranslateSimpleKey(): void
    {
        I18n::load('en', ['hello' => 'Hello!']);
        self::assertSame('Hello!', I18n::t('hello'));
    }

    public function testTranslateWithExplicitLocale(): void
    {
        I18n::load('en', ['hello' => 'Hello!']);
        I18n::load('ja', ['hello' => 'こんにちは！']);
        self::assertSame('こんにちは！', I18n::t('hello', [], 'ja'));
    }

    public function testTranslateUsesDefaultLocale(): void
    {
        I18n::setLocale('ja');
        I18n::load('ja', ['hello' => 'こんにちは！']);
        self::assertSame('こんにちは！', I18n::t('hello'));
    }

    // --- placeholder substitution ---

    public function testSinglePlaceholder(): void
    {
        I18n::load('en', ['greeting' => 'Hello, {name}!']);
        self::assertSame('Hello, Taro!', I18n::t('greeting', ['name' => 'Taro']));
    }

    public function testMultiplePlaceholders(): void
    {
        I18n::load('en', ['msg' => '{a} and {b}']);
        self::assertSame('foo and bar', I18n::t('msg', ['a' => 'foo', 'b' => 'bar']));
    }

    public function testPlaceholderRepeatedInMessage(): void
    {
        I18n::load('en', ['msg' => '{x}-{x}-{x}']);
        self::assertSame('go-go-go', I18n::t('msg', ['x' => 'go']));
    }

    public function testUnknownPlaceholderLeftAsIs(): void
    {
        I18n::load('en', ['msg' => 'Hello, {name}!']);
        // no 'name' in params — placeholder stays
        self::assertSame('Hello, {name}!', I18n::t('msg'));
    }

    public function testNoPlaceholdersInMessage(): void
    {
        I18n::load('en', ['msg' => 'Static text']);
        self::assertSame('Static text', I18n::t('msg', ['unused' => 'x']));
    }

    // --- fallback behaviour ---

    public function testFallsBackToDefaultLocaleWhenKeyMissingInRequested(): void
    {
        I18n::setLocale('en');
        I18n::load('en', ['greeting' => 'Hello!']);
        // 'ja' has no 'greeting' — should fall back to 'en'
        self::assertSame('Hello!', I18n::t('greeting', [], 'ja'));
    }

    public function testReturnsKeyWhenNotFoundAnywhere(): void
    {
        self::assertSame('missing.key', I18n::t('missing.key'));
    }

    public function testReturnsKeyWhenLocaleEmpty(): void
    {
        // no catalog loaded — key fallback
        self::assertSame('app.title', I18n::t('app.title'));
    }

    // --- load merging ---

    public function testLoadMergesWithExistingCatalog(): void
    {
        I18n::load('en', ['a' => 'A']);
        I18n::load('en', ['b' => 'B']);
        self::assertSame('A', I18n::t('a'));
        self::assertSame('B', I18n::t('b'));
    }

    public function testLoadLaterKeyWins(): void
    {
        I18n::load('en', ['msg' => 'first']);
        I18n::load('en', ['msg' => 'second']);
        self::assertSame('second', I18n::t('msg'));
    }

    // --- reset ---

    public function testResetClearsAllState(): void
    {
        I18n::setLocale('ja');
        I18n::load('ja', ['k' => 'v']);
        I18n::reset();
        self::assertSame('en', I18n::locale());
        self::assertSame('k', I18n::t('k'));
    }

    // --- edge cases ---

    public function testEmptyMessageString(): void
    {
        I18n::load('en', ['empty' => '']);
        self::assertSame('', I18n::t('empty'));
    }

    public function testEmptyKeyReturnsEmptyKey(): void
    {
        self::assertSame('', I18n::t(''));
    }

    public function testLocaleWithRegionCode(): void
    {
        I18n::load('zh-TW', ['hello' => '你好！']);
        self::assertSame('你好！', I18n::t('hello', [], 'zh-TW'));
    }
}
