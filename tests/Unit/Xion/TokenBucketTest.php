<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\TokenBucket;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TokenBucket.
 */
final class TokenBucketTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE token_buckets (
                bucket_key  VARCHAR(255) NOT NULL PRIMARY KEY,
                tokens      DOUBLE       NOT NULL,
                last_refill DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
    }

    private function bucket(float $capacity = 5.0, float $refillRate = 1.0): TokenBucket
    {
        return new TokenBucket($this->db, $capacity, $refillRate);
    }

    // ── constructor ───────────────────────────────────────────────────────────

    public function testConstructorThrowsOnNonPositiveCapacity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TokenBucket($this->db, 0.0, 1.0);
    }

    public function testConstructorThrowsOnNonPositiveRefillRate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TokenBucket($this->db, 10.0, 0.0);
    }

    // ── consume ───────────────────────────────────────────────────────────────

    public function testConsumeReturnsTrueOnFirstRequest(): void
    {
        $tb = $this->bucket(capacity: 5.0);
        $this->assertTrue($tb->consume('user-1'));
    }

    public function testConsumeDecreasesTokens(): void
    {
        $tb = $this->bucket(capacity: 5.0, refillRate: 0.0001);
        $tb->consume('user-1'); // 5 - 1 = 4
        $tb->consume('user-1'); // 4 - 1 = 3
        $this->assertEqualsWithDelta(3.0, $tb->tokens('user-1'), 0.5);
    }

    public function testConsumeReturnsFalseWhenEmpty(): void
    {
        $tb = $this->bucket(capacity: 2.0, refillRate: 0.0001);
        $tb->consume('user-1');
        $tb->consume('user-1');
        $this->assertFalse($tb->consume('user-1'));
    }

    public function testConsumeMultipleTokens(): void
    {
        $tb = $this->bucket(capacity: 5.0, refillRate: 0.0001);
        $this->assertTrue($tb->consume('user-1', 3));
        $this->assertEqualsWithDelta(2.0, $tb->tokens('user-1'), 0.5);
    }

    public function testConsumeReturnsFalseIfInsufficientTokensForCost(): void
    {
        $tb = $this->bucket(capacity: 3.0, refillRate: 0.0001);
        $tb->consume('user-1', 2); // 3 - 2 = 1
        $this->assertFalse($tb->consume('user-1', 3)); // only 1 left
    }

    public function testConsumeIsBucketKeyScoped(): void
    {
        $tb = $this->bucket(capacity: 2.0, refillRate: 0.0001);
        $tb->consume('user-1');
        $tb->consume('user-1');
        $this->assertTrue($tb->consume('user-2')); // different bucket
    }

    public function testConsumeThrowsOnEmptyBucketKey(): void
    {
        $tb = $this->bucket();
        $this->expectException(\InvalidArgumentException::class);
        $tb->consume('');
    }

    public function testConsumeThrowsOnNonPositiveCost(): void
    {
        $tb = $this->bucket();
        $this->expectException(\InvalidArgumentException::class);
        $tb->consume('user-1', 0);
    }

    // ── tokens ────────────────────────────────────────────────────────────────

    public function testTokensReturnsFullCapacityForNewBucket(): void
    {
        $tb = $this->bucket(capacity: 10.0);
        $this->assertSame(10.0, $tb->tokens('user-1'));
    }

    public function testTokensDoesNotModifyBucket(): void
    {
        $tb = $this->bucket(capacity: 5.0, refillRate: 0.0001);
        $tb->tokens('user-1');
        $tb->tokens('user-1');
        $this->assertTrue($tb->consume('user-1'));
    }

    public function testTokensThrowsOnEmptyBucketKey(): void
    {
        $tb = $this->bucket();
        $this->expectException(\InvalidArgumentException::class);
        $tb->tokens('');
    }

    // ── reset ─────────────────────────────────────────────────────────────────

    public function testResetRestoresFullCapacity(): void
    {
        $tb = $this->bucket(capacity: 3.0, refillRate: 0.0001);
        $tb->consume('user-1');
        $tb->consume('user-1');
        $tb->consume('user-1'); // empty
        $tb->reset('user-1');
        $this->assertEqualsWithDelta(3.0, $tb->tokens('user-1'), 0.1);
    }

    public function testResetThrowsOnEmptyBucketKey(): void
    {
        $tb = $this->bucket();
        $this->expectException(\InvalidArgumentException::class);
        $tb->reset('');
    }

    // ── remove ────────────────────────────────────────────────────────────────

    public function testRemoveDeletesBucketRecord(): void
    {
        $tb = $this->bucket(capacity: 5.0, refillRate: 0.0001);
        $tb->consume('user-1');
        $this->assertTrue($tb->remove('user-1'));
        // After removal, bucket is considered full (no record = new bucket)
        $this->assertSame(5.0, $tb->tokens('user-1'));
    }

    public function testRemoveReturnsFalseIfNoRecord(): void
    {
        $tb = $this->bucket();
        $this->assertFalse($tb->remove('nonexistent'));
    }

    // ── purgeStale ────────────────────────────────────────────────────────────

    public function testPurgeStaleDeletesOldRecords(): void
    {
        $tb = $this->bucket(capacity: 5.0, refillRate: 0.0001);
        $tb->consume('user-1');
        // Manually age the record
        $this->db->exec(
            "UPDATE token_buckets SET last_refill = datetime('now', '-2 days') WHERE bucket_key = 'user-1'"
        );
        $this->assertSame(1, $tb->purgeStale(86400)); // purge older than 1 day
    }

    public function testPurgeStalePreservesRecentRecords(): void
    {
        $tb = $this->bucket(capacity: 5.0, refillRate: 0.0001);
        $tb->consume('user-1');
        $this->assertSame(0, $tb->purgeStale(86400));
    }
}
