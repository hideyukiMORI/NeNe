<?php

declare(strict_types=1);

namespace Tests\Unit\Xion;

use Nene\Xion\MultiLangContent;
use PDO;
use PHPUnit\Framework\TestCase;

final class MultiLangContentTest extends TestCase
{
    private PDO $pdo;
    private MultiLangContent $ml;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE multilang_content (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                content_key VARCHAR(255) NOT NULL,
                locale      VARCHAR(20)  NOT NULL,
                value       TEXT         NOT NULL,
                updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (content_key, locale)
            )
        ');
        $this->ml = new MultiLangContent($this->pdo);
    }

    // ── set ───────────────────────────────────────────────────────────────────

    public function testSetStoresValue(): void
    {
        $this->ml->set('greeting', 'en', 'Hello!');
        $this->assertSame('Hello!', $this->ml->get('greeting', 'en'));
    }

    public function testSetUpdatesExistingValue(): void
    {
        $this->ml->set('greeting', 'en', 'Hello!');
        $this->ml->set('greeting', 'en', 'Hi!');
        $this->assertSame('Hi!', $this->ml->get('greeting', 'en'));
    }

    public function testSetNormalisesKeyToLowercase(): void
    {
        $this->ml->set('GREETING', 'en', 'Hello!');
        $this->assertSame('Hello!', $this->ml->get('greeting', 'en'));
    }

    public function testSetNormalisesLocaleToLowercase(): void
    {
        $this->ml->set('greeting', 'EN', 'Hello!');
        $this->assertSame('Hello!', $this->ml->get('greeting', 'en'));
    }

    public function testSetThrowsOnEmptyKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ml->set('', 'en', 'Hello!');
    }

    public function testSetThrowsOnEmptyLocale(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ml->set('greeting', '', 'Hello!');
    }

    public function testSetThrowsOnEmptyValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ml->set('greeting', 'en', '');
    }

    // ── get ───────────────────────────────────────────────────────────────────

    public function testGetReturnsNullWhenNotFound(): void
    {
        $this->assertNull($this->ml->get('unknown', 'en'));
    }

    public function testGetFallsBackToFallbackLocale(): void
    {
        $this->ml->set('greeting', 'en', 'Hello!');
        $result = $this->ml->get('greeting', 'fr', 'en');
        $this->assertSame('Hello!', $result);
    }

    public function testGetReturnsPrimaryLocaleOverFallback(): void
    {
        $this->ml->set('greeting', 'en', 'Hello!');
        $this->ml->set('greeting', 'fr', 'Bonjour!');
        $result = $this->ml->get('greeting', 'fr', 'en');
        $this->assertSame('Bonjour!', $result);
    }

    public function testGetReturnsNullWhenBothLocalesMissing(): void
    {
        $this->assertNull($this->ml->get('greeting', 'fr', 'de'));
    }

    // ── getAll ────────────────────────────────────────────────────────────────

    public function testGetAllReturnsLocaleValueMap(): void
    {
        $this->ml->set('greeting', 'en', 'Hello!');
        $this->ml->set('greeting', 'ja', 'こんにちは！');
        $all = $this->ml->getAll('greeting');
        $this->assertArrayHasKey('en', $all);
        $this->assertArrayHasKey('ja', $all);
        $this->assertSame('Hello!', $all['en']);
        $this->assertSame('こんにちは！', $all['ja']);
    }

    public function testGetAllReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->ml->getAll('unknown'));
    }

    // ── getLocales ────────────────────────────────────────────────────────────

    public function testGetLocalesReturnsAvailableLocales(): void
    {
        $this->ml->set('greeting', 'en', 'Hello!');
        $this->ml->set('greeting', 'ja', 'こんにちは！');
        $locales = $this->ml->getLocales('greeting');
        $this->assertContains('en', $locales);
        $this->assertContains('ja', $locales);
    }

    public function testGetLocalesReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->ml->getLocales('unknown'));
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDeleteRemovesLocaleEntry(): void
    {
        $this->ml->set('greeting', 'en', 'Hello!');
        $this->ml->set('greeting', 'ja', 'こんにちは！');
        $this->assertTrue($this->ml->delete('greeting', 'en'));
        $this->assertNull($this->ml->get('greeting', 'en'));
        $this->assertNotNull($this->ml->get('greeting', 'ja'));
    }

    public function testDeleteReturnsFalseWhenNotFound(): void
    {
        $this->assertFalse($this->ml->delete('unknown', 'en'));
    }

    // ── deleteKey ─────────────────────────────────────────────────────────────

    public function testDeleteKeyRemovesAllLocales(): void
    {
        $this->ml->set('greeting', 'en', 'Hello!');
        $this->ml->set('greeting', 'ja', 'こんにちは！');
        $this->assertSame(2, $this->ml->deleteKey('greeting'));
        $this->assertSame([], $this->ml->getAll('greeting'));
    }

    public function testDeleteKeyReturnsZeroWhenNone(): void
    {
        $this->assertSame(0, $this->ml->deleteKey('unknown'));
    }
}
