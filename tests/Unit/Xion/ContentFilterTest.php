<?php

declare(strict_types=1);

namespace Tests\Unit\Xion;

use Nene\Xion\ContentFilter;
use PDO;
use PHPUnit\Framework\TestCase;

final class ContentFilterTest extends TestCase
{
    private PDO $pdo;
    private ContentFilter $cf;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE banned_words (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                word       VARCHAR(255) NOT NULL,
                category   VARCHAR(100) NOT NULL DEFAULT \'\',
                added_by   VARCHAR(255) NOT NULL DEFAULT \'\',
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (word)
            )
        ');
        $this->cf = new ContentFilter($this->pdo);
    }

    // ── addWord ───────────────────────────────────────────────────────────────

    public function testAddWordStoresWord(): void
    {
        $this->cf->addWord('badword');
        $this->assertSame(1, $this->cf->wordCount());
    }

    public function testAddWordNormalisesToLowercase(): void
    {
        $this->cf->addWord('BadWord');
        $words = $this->cf->listWords();
        $this->assertSame('badword', $words[0]['word']);
    }

    public function testAddWordIsIdempotent(): void
    {
        $this->cf->addWord('badword');
        $this->cf->addWord('badword');
        $this->assertSame(1, $this->cf->wordCount());
    }

    public function testAddWordThrowsOnEmptyWord(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cf->addWord('');
    }

    public function testAddWordStoresCategory(): void
    {
        $this->cf->addWord('spam', 'marketing', 'admin');
        $words = $this->cf->listWords('marketing');
        $this->assertCount(1, $words);
        $this->assertSame('admin', $words[0]['added_by']);
    }

    // ── removeWord ────────────────────────────────────────────────────────────

    public function testRemoveWordDeletesEntry(): void
    {
        $this->cf->addWord('badword');
        $result = $this->cf->removeWord('badword');
        $this->assertTrue($result);
        $this->assertSame(0, $this->cf->wordCount());
    }

    public function testRemoveWordReturnsFalseWhenAbsent(): void
    {
        $this->assertFalse($this->cf->removeWord('nothere'));
    }

    // ── listWords ─────────────────────────────────────────────────────────────

    public function testListWordsReturnsAll(): void
    {
        $this->cf->addWord('alpha');
        $this->cf->addWord('beta');
        $words = $this->cf->listWords();
        $this->assertCount(2, $words);
    }

    public function testListWordsFiltersByCategory(): void
    {
        $this->cf->addWord('spam', 'marketing');
        $this->cf->addWord('hate', 'abuse');
        $this->assertCount(1, $this->cf->listWords('marketing'));
        $this->assertCount(1, $this->cf->listWords('abuse'));
    }

    // ── contains ──────────────────────────────────────────────────────────────

    public function testContainsReturnsTrueWhenFound(): void
    {
        $this->cf->addWord('badword');
        $this->assertTrue($this->cf->contains('This has badword in it'));
    }

    public function testContainsReturnsFalseWhenClean(): void
    {
        $this->cf->addWord('badword');
        $this->assertFalse($this->cf->contains('This is perfectly fine'));
    }

    public function testContainsIsCaseInsensitive(): void
    {
        $this->cf->addWord('badword');
        $this->assertTrue($this->cf->contains('This has BADWORD in it'));
    }

    public function testContainsReturnsFalseWhenNoBannedWords(): void
    {
        $this->assertFalse($this->cf->contains('any text'));
    }

    // ── detect ────────────────────────────────────────────────────────────────

    public function testDetectReturnsFoundWords(): void
    {
        $this->cf->addWord('foo');
        $this->cf->addWord('bar');
        $found = $this->cf->detect('This has foo and bar in it');
        $this->assertSame(['bar', 'foo'], $found);
    }

    public function testDetectDeduplicates(): void
    {
        $this->cf->addWord('foo');
        $found = $this->cf->detect('foo foo foo');
        $this->assertSame(['foo'], $found);
    }

    public function testDetectReturnsEmptyWhenClean(): void
    {
        $this->cf->addWord('bad');
        $this->assertSame([], $this->cf->detect('This is fine'));
    }

    public function testDetectWholeWordSkipsSubstrings(): void
    {
        $this->cf->addWord('ass');
        // substring match (default)
        $this->assertTrue($this->cf->contains('class'));
        // whole-word match should NOT match 'class'
        $this->assertFalse($this->cf->contains('class', true));
        $this->assertTrue($this->cf->contains('this ass hurts', true));
    }

    // ── mask ──────────────────────────────────────────────────────────────────

    public function testMaskReplacesWithAsterisks(): void
    {
        $this->cf->addWord('badword');
        $result = $this->cf->mask('This has badword in it');
        $this->assertSame('This has ******* in it', $result);
    }

    public function testMaskIsCaseInsensitive(): void
    {
        $this->cf->addWord('badword');
        $result = $this->cf->mask('This has BADWORD in it');
        $this->assertSame('This has ******* in it', $result);
    }

    public function testMaskCustomChar(): void
    {
        $this->cf->addWord('bad');
        $result = $this->cf->mask('so bad', '#');
        $this->assertSame('so ###', $result);
    }

    public function testMaskReturnsUnchangedWhenNoBannedWords(): void
    {
        $text = 'clean text here';
        $this->assertSame($text, $this->cf->mask($text));
    }

    public function testMaskMultipleWords(): void
    {
        $this->cf->addWord('foo');
        $this->cf->addWord('bar');
        $result = $this->cf->mask('foo and bar');
        $this->assertSame('*** and ***', $result);
    }

    // ── cache invalidation ────────────────────────────────────────────────────

    public function testFlushCacheAllowsReload(): void
    {
        $this->cf->addWord('alpha');
        $this->assertTrue($this->cf->contains('alpha'));
        $this->cf->removeWord('alpha');
        $this->cf->flushCache();
        $this->assertFalse($this->cf->contains('alpha'));
    }
}
