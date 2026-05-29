<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\SearchSuggestion;
use PDO;
use PHPUnit\Framework\TestCase;

final class SearchSuggestionTest extends TestCase
{
    private PDO $pdo;
    private SearchSuggestion $ss;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE search_suggestions (
                id        INTEGER PRIMARY KEY AUTOINCREMENT,
                term      VARCHAR(500) NOT NULL UNIQUE,
                frequency INTEGER      NOT NULL DEFAULT 0,
                weight    INTEGER      NOT NULL DEFAULT 0,
                last_seen DATETIME     NULL
            )
        ');
        $this->ss = new SearchSuggestion($this->pdo);
    }

    // ── record ────────────────────────────────────────────────────────────────

    public function testRecordIncreasesFrequency(): void
    {
        $this->ss->record('PHP framework');
        $this->ss->record('PHP framework');
        $this->assertSame(2, $this->ss->frequency('php framework'));
    }

    public function testRecordNormalisesToLowercase(): void
    {
        $this->ss->record('PHP Framework');
        $this->ss->record('php framework');
        $this->assertSame(2, $this->ss->frequency('php framework'));
    }

    public function testRecordCollapseWhitespace(): void
    {
        $this->ss->record('php  framework');
        $this->assertSame(1, $this->ss->frequency('php framework'));
    }

    public function testRecordThrowsOnEmptyTerm(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ss->record('');
    }

    public function testRecordThrowsOnWhitespaceTerm(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ss->record('   ');
    }

    // ── suggest ───────────────────────────────────────────────────────────────

    public function testSuggestReturnsPrefixMatches(): void
    {
        $this->ss->record('php framework');
        $this->ss->record('php tutorial');
        $this->ss->record('python guide');
        $results = $this->ss->suggest('php');
        $terms   = array_column($results, 'term');
        $this->assertContains('php framework', $terms);
        $this->assertContains('php tutorial', $terms);
        $this->assertNotContains('python guide', $terms);
    }

    public function testSuggestOrdersByFrequencyDesc(): void
    {
        $this->ss->record('php tutorial');
        $this->ss->record('php framework');
        $this->ss->record('php framework');
        $results = $this->ss->suggest('php');
        $this->assertSame('php framework', $results[0]['term']);
    }

    public function testSuggestRespectsLimit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->ss->record('php term' . $i);
        }
        $results = $this->ss->suggest('php', 3);
        $this->assertCount(3, $results);
    }

    public function testSuggestReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->ss->suggest('xyz'));
    }

    public function testSuggestIsCaseInsensitive(): void
    {
        $this->ss->record('PHP framework');
        $results = $this->ss->suggest('PHP');
        $this->assertCount(1, $results);
        $this->assertSame('php framework', $results[0]['term']);
    }

    // ── boost ─────────────────────────────────────────────────────────────────

    public function testBoostUpdatesWeight(): void
    {
        $this->ss->record('php tutorial');
        $this->assertTrue($this->ss->boost('php tutorial', 10));
        $results = $this->ss->suggest('php');
        $this->assertSame(10, (int)$results[0]['weight']);
    }

    public function testBoostElevatesSuggestionOrder(): void
    {
        $this->ss->record('php framework');
        $this->ss->record('php framework');
        $this->ss->record('php tutorial');
        $this->ss->boost('php tutorial', 5);
        $results = $this->ss->suggest('php');
        $this->assertSame('php tutorial', $results[0]['term']);
    }

    public function testBoostReturnsFalseForMissingTerm(): void
    {
        $this->assertFalse($this->ss->boost('unknown', 5));
    }

    public function testBoostThrowsOnNegativeWeight(): void
    {
        $this->ss->record('php tutorial');
        $this->expectException(\InvalidArgumentException::class);
        $this->ss->boost('php tutorial', -1);
    }

    // ── remove ────────────────────────────────────────────────────────────────

    public function testRemoveDeletesTerm(): void
    {
        $this->ss->record('php framework');
        $this->assertTrue($this->ss->remove('php framework'));
        $this->assertSame(0, $this->ss->frequency('php framework'));
        $this->assertSame([], $this->ss->suggest('php'));
    }

    public function testRemoveReturnsFalseForMissingTerm(): void
    {
        $this->assertFalse($this->ss->remove('nonexistent'));
    }

    // ── purge ─────────────────────────────────────────────────────────────────

    public function testPurgeRemovesOldEntries(): void
    {
        // Insert a stale entry directly
        $this->pdo->exec("INSERT INTO search_suggestions (term, frequency, last_seen)
                          VALUES ('stale term', 1, '2020-01-01 00:00:00')");
        $this->ss->record('recent term');
        $deleted = $this->ss->purge('2025-01-01 00:00:00');
        $this->assertSame(1, $deleted);
        $this->assertSame(0, $this->ss->frequency('stale term'));
        $this->assertSame(1, $this->ss->frequency('recent term'));
    }

    // ── frequency ─────────────────────────────────────────────────────────────

    public function testFrequencyReturnsZeroForUnknownTerm(): void
    {
        $this->assertSame(0, $this->ss->frequency('unknown'));
    }
}
