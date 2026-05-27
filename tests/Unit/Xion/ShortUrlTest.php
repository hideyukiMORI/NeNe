<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\ShortUrl;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ShortUrl.
 */
final class ShortUrlTest extends TestCase
{
    private PDO $db;
    private ShortUrl $su;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE short_urls (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                code       VARCHAR(20)  NOT NULL UNIQUE,
                target_url TEXT         NOT NULL,
                is_active  TINYINT(1)   NOT NULL DEFAULT 1,
                expires_at DATETIME     DEFAULT NULL,
                clicks     INTEGER      NOT NULL DEFAULT 0,
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->su = new ShortUrl($this->db);
    }

    // ── shorten ───────────────────────────────────────────────────────────────

    public function testShortenReturnsCode(): void
    {
        $code = $this->su->shorten('https://example.com');
        $this->assertNotEmpty($code);
    }

    public function testShortenWithSlugUsesSlug(): void
    {
        $code = $this->su->shorten('https://example.com', slug: 'test01');
        $this->assertSame('test01', $code);
    }

    public function testShortenThrowsOnDuplicateSlug(): void
    {
        $this->su->shorten('https://example.com', slug: 'dup');
        $this->expectException(\RuntimeException::class);
        $this->su->shorten('https://other.com', slug: 'dup');
    }

    public function testShortenThrowsOnEmptyUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->su->shorten('');
    }

    // ── resolve ───────────────────────────────────────────────────────────────

    public function testResolveReturnsTargetUrl(): void
    {
        $code = $this->su->shorten('https://example.com/path');
        $this->assertSame('https://example.com/path', $this->su->resolve($code));
    }

    public function testResolveIncrementsClickCount(): void
    {
        $code = $this->su->shorten('https://example.com');
        $this->su->resolve($code);
        $this->su->resolve($code);
        $this->assertSame(2, $this->su->clickCount($code));
    }

    public function testResolveReturnsNullForUnknownCode(): void
    {
        $this->assertNull($this->su->resolve('notexist'));
    }

    public function testResolveReturnsNullForInactiveUrl(): void
    {
        $code = $this->su->shorten('https://example.com');
        $this->su->deactivate($code);
        $this->assertNull($this->su->resolve($code));
    }

    public function testResolveReturnsNullForExpiredUrl(): void
    {
        // Insert an expired URL directly
        $this->db->exec(
            "INSERT INTO short_urls (code, target_url, expires_at)
             VALUES ('exp', 'https://old.com', '2000-01-01 00:00:00')"
        );
        $this->assertNull($this->su->resolve('exp'));
    }

    // ── find ──────────────────────────────────────────────────────────────────

    public function testFindReturnsRowWithoutClickIncrement(): void
    {
        $code = $this->su->shorten('https://example.com');
        $row  = $this->su->find($code);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(0, (int)$row['clicks']); // no click recorded
    }

    public function testFindReturnsNullForUnknownCode(): void
    {
        $this->assertNull($this->su->find('nope'));
    }

    // ── clickCount ────────────────────────────────────────────────────────────

    public function testClickCountReturnsZeroBeforeAnyResolve(): void
    {
        $code = $this->su->shorten('https://example.com');
        $this->assertSame(0, $this->su->clickCount($code));
    }

    public function testClickCountReturnsZeroForNonExistentCode(): void
    {
        $this->assertSame(0, $this->su->clickCount('notexist'));
    }

    // ── deactivate / reactivate ───────────────────────────────────────────────

    public function testDeactivateStopsResolution(): void
    {
        $code = $this->su->shorten('https://example.com');
        $this->assertTrue($this->su->deactivate($code));
        $this->assertNull($this->su->resolve($code));
    }

    public function testReactivateRestoresResolution(): void
    {
        $code = $this->su->shorten('https://example.com');
        $this->su->deactivate($code);
        $this->assertTrue($this->su->reactivate($code));
        $this->assertNotNull($this->su->resolve($code));
    }

    public function testDeactivateReturnsFalseIfAlreadyInactive(): void
    {
        $code = $this->su->shorten('https://example.com');
        $this->su->deactivate($code);
        $this->assertFalse($this->su->deactivate($code));
    }

    // ── remove ────────────────────────────────────────────────────────────────

    public function testRemoveDeletesEntry(): void
    {
        $code = $this->su->shorten('https://example.com');
        $this->assertTrue($this->su->remove($code));
        $this->assertNull($this->su->find($code));
    }

    public function testRemoveReturnsFalseForNonExistentCode(): void
    {
        $this->assertFalse($this->su->remove('nope'));
    }
}
