<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\Quota;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Quota.
 */
final class QuotaTest extends TestCase
{
    private PDO $db;
    private Quota $quota;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE user_quotas (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id    VARCHAR(255) NOT NULL,
                resource   VARCHAR(100) NOT NULL,
                used       BIGINT       NOT NULL DEFAULT 0,
                quota      BIGINT       NOT NULL DEFAULT 0,
                resets_at  DATETIME     NULL,
                updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (user_id, resource)
            )
        ');
        $this->quota = new Quota($this->db);
    }

    // ── grant ─────────────────────────────────────────────────────────────────

    public function testGrantCreatesRow(): void
    {
        $this->quota->grant('user-1', 'api_calls', 1000);
        $info = $this->quota->check('user-1', 'api_calls');
        $this->assertNotNull($info);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(0, $info['used']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(1000, $info['quota']);
    }

    public function testGrantUpdatesExistingRow(): void
    {
        $this->quota->grant('user-1', 'api_calls', 500);
        $this->quota->grant('user-1', 'api_calls', 2000);
        $info = $this->quota->check('user-1', 'api_calls');
        $this->assertNotNull($info);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(2000, $info['quota']);
    }

    public function testGrantWithTtlSetsResetsAt(): void
    {
        $this->quota->grant('user-1', 'storage', 100, ttlSeconds: 3600);
        $info = $this->quota->check('user-1', 'storage');
        $this->assertNotNull($info);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertNotNull($info['resets_at']);
    }

    public function testGrantThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->quota->grant('', 'api_calls', 100);
    }

    public function testGrantThrowsOnEmptyResource(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->quota->grant('user-1', '', 100);
    }

    // ── consume ───────────────────────────────────────────────────────────────

    public function testConsumeReturnsTrueWhenNoQuotaConfigured(): void
    {
        $this->assertTrue($this->quota->consume('user-1', 'api_calls'));
    }

    public function testConsumeReturnsTrueWhenWithinLimit(): void
    {
        $this->quota->grant('user-1', 'api_calls', 10);
        $this->assertTrue($this->quota->consume('user-1', 'api_calls', 5));
    }

    public function testConsumeReturnsFalseWhenLimitExceeded(): void
    {
        $this->quota->grant('user-1', 'api_calls', 5);
        $this->quota->consume('user-1', 'api_calls', 5); // use it up
        $this->assertFalse($this->quota->consume('user-1', 'api_calls', 1));
    }

    public function testConsumeIncrementsUsed(): void
    {
        $this->quota->grant('user-1', 'api_calls', 100);
        $this->quota->consume('user-1', 'api_calls', 3);
        $this->quota->consume('user-1', 'api_calls', 7);
        $info = $this->quota->check('user-1', 'api_calls');
        $this->assertNotNull($info);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(10, $info['used']);
    }

    public function testConsumeUnlimitedQuota(): void
    {
        $this->quota->grant('user-1', 'api_calls', 0); // 0 = unlimited
        for ($i = 0; $i < 1000; $i++) {
            $this->assertTrue($this->quota->consume('user-1', 'api_calls'));
        }
    }

    // ── check ─────────────────────────────────────────────────────────────────

    public function testCheckReturnsNullWhenNotConfigured(): void
    {
        $this->assertNull($this->quota->check('user-1', 'api_calls'));
    }

    public function testCheckReturnsUsageInfo(): void
    {
        $this->quota->grant('user-1', 'api_calls', 50);
        $this->quota->consume('user-1', 'api_calls', 20);
        $info = $this->quota->check('user-1', 'api_calls');
        $this->assertNotNull($info);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(20, $info['used']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(50, $info['quota']);
    }

    // ── exceeded ──────────────────────────────────────────────────────────────

    public function testExceededReturnsFalseWhenNotConfigured(): void
    {
        $this->assertFalse($this->quota->exceeded('user-1', 'api_calls'));
    }

    public function testExceededReturnsFalseWhenUnder(): void
    {
        $this->quota->grant('user-1', 'api_calls', 10);
        $this->quota->consume('user-1', 'api_calls', 5);
        $this->assertFalse($this->quota->exceeded('user-1', 'api_calls'));
    }

    public function testExceededReturnsTrueWhenAtLimit(): void
    {
        $this->quota->grant('user-1', 'api_calls', 5);
        $this->quota->consume('user-1', 'api_calls', 5);
        $this->assertTrue($this->quota->exceeded('user-1', 'api_calls'));
    }

    // ── reset ─────────────────────────────────────────────────────────────────

    public function testResetClearsUsage(): void
    {
        $this->quota->grant('user-1', 'api_calls', 100);
        $this->quota->consume('user-1', 'api_calls', 50);
        $this->quota->reset('user-1', 'api_calls');
        $info = $this->quota->check('user-1', 'api_calls');
        $this->assertNotNull($info);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(0, $info['used']);
    }

    public function testResetReturnsFalseForUnknown(): void
    {
        $this->assertFalse($this->quota->reset('user-1', 'unknown'));
    }

    // ── usage ─────────────────────────────────────────────────────────────────

    public function testUsageListsAllResources(): void
    {
        $this->quota->grant('user-1', 'api_calls', 100);
        $this->quota->grant('user-1', 'storage', 200);
        $list = $this->quota->usage('user-1');
        $this->assertCount(2, $list);
    }

    public function testUsageReturnsEmptyForUnknownUser(): void
    {
        $this->assertSame([], $this->quota->usage('nobody'));
    }

    // ── revoke ────────────────────────────────────────────────────────────────

    public function testRevokeRemovesQuota(): void
    {
        $this->quota->grant('user-1', 'api_calls', 100);
        $this->assertTrue($this->quota->revoke('user-1', 'api_calls'));
        $this->assertNull($this->quota->check('user-1', 'api_calls'));
    }

    public function testRevokeReturnsFalseForUnknown(): void
    {
        $this->assertFalse($this->quota->revoke('user-1', 'unknown'));
    }
}
