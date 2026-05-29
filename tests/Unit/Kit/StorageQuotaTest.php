<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\StorageQuota;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for StorageQuota.
 */
final class StorageQuotaTest extends TestCase
{
    private PDO $db;
    private StorageQuota $sq;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE storage_quotas (
                owner_type  VARCHAR(100) NOT NULL,
                owner_id    VARCHAR(255) NOT NULL,
                used_bytes  BIGINT       NOT NULL DEFAULT 0,
                limit_bytes BIGINT       DEFAULT NULL,
                updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (owner_type, owner_id)
            )
        ');
        $this->sq = new StorageQuota($this->db);
    }

    // ── setLimit ──────────────────────────────────────────────────────────────

    public function testSetLimitCreatesRecord(): void
    {
        $this->sq->setLimit('user', 'user-1', 1000);
        $info = $this->sq->usage('user', 'user-1');
        $this->assertNotNull($info);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(1000, $info['limit']);
    }

    public function testSetLimitUpdatesExistingRecord(): void
    {
        $this->sq->setLimit('user', 'user-1', 1000);
        $this->sq->setLimit('user', 'user-1', 2000);
        $info = $this->sq->usage('user', 'user-1');
        $this->assertNotNull($info);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(2000, $info['limit']);
    }

    public function testSetLimitNullRemovesLimit(): void
    {
        $this->sq->setLimit('user', 'user-1', 1000);
        $this->sq->setLimit('user', 'user-1', null);
        $info = $this->sq->usage('user', 'user-1');
        $this->assertNotNull($info);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertNull($info['limit']);
    }

    public function testSetLimitThrowsOnNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sq->setLimit('user', 'user-1', -1);
    }

    // ── add ───────────────────────────────────────────────────────────────────

    public function testAddIncreasesUsage(): void
    {
        $this->sq->setLimit('user', 'user-1', 10000);
        $this->sq->add('user', 'user-1', 500);
        $info = $this->sq->usage('user', 'user-1');
        $this->assertNotNull($info);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(500, $info['used']);
    }

    public function testAddAccumulatesMultipleCalls(): void
    {
        $this->sq->setLimit('user', 'user-1', 10000);
        $this->sq->add('user', 'user-1', 300);
        $this->sq->add('user', 'user-1', 200);
        $info = $this->sq->usage('user', 'user-1');
        $this->assertNotNull($info);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(500, $info['used']);
    }

    public function testAddCreatesRecordIfNotExists(): void
    {
        // No setLimit call — unlimited owner
        $this->sq->add('user', 'user-new', 100);
        $info = $this->sq->usage('user', 'user-new');
        $this->assertNotNull($info);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(100, $info['used']);
    }

    public function testAddThrowsIfZeroBytes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sq->add('user', 'user-1', 0);
    }

    public function testAddThrowsOnQuotaExceeded(): void
    {
        $this->sq->setLimit('user', 'user-1', 100);
        $this->sq->add('user', 'user-1', 90);
        $this->expectException(\OverflowException::class);
        $this->sq->add('user', 'user-1', 20); // 90 + 20 > 100
    }

    // ── release ───────────────────────────────────────────────────────────────

    public function testReleaseDecreasesUsage(): void
    {
        $this->sq->setLimit('user', 'user-1', 10000);
        $this->sq->add('user', 'user-1', 500);
        $this->sq->release('user', 'user-1', 200);
        $info = $this->sq->usage('user', 'user-1');
        $this->assertNotNull($info);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(300, $info['used']);
    }

    public function testReleaseDoesNotGoBelowZero(): void
    {
        $this->sq->setLimit('user', 'user-1', 10000);
        $this->sq->add('user', 'user-1', 100);
        $this->sq->release('user', 'user-1', 500); // more than used
        $info = $this->sq->usage('user', 'user-1');
        $this->assertNotNull($info);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(0, $info['used']);
    }

    public function testReleaseThrowsOnNonPositiveBytes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sq->release('user', 'user-1', 0);
    }

    // ── usage ─────────────────────────────────────────────────────────────────

    public function testUsageReturnsNullForUnknownOwner(): void
    {
        $this->assertNull($this->sq->usage('user', 'nobody'));
    }

    public function testUsageAvailableReflectsLimit(): void
    {
        $this->sq->setLimit('user', 'user-1', 1000);
        $this->sq->add('user', 'user-1', 300);
        $info = $this->sq->usage('user', 'user-1');
        $this->assertNotNull($info);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(700, $info['available']);
    }

    public function testUsageAvailableIsNullWithoutLimit(): void
    {
        $this->sq->add('user', 'user-1', 300);
        $info = $this->sq->usage('user', 'user-1');
        $this->assertNotNull($info);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertNull($info['available']);
    }

    // ── wouldExceed ───────────────────────────────────────────────────────────

    public function testWouldExceedReturnsTrueOverLimit(): void
    {
        $this->sq->setLimit('user', 'user-1', 100);
        $this->sq->add('user', 'user-1', 90);
        $this->assertTrue($this->sq->wouldExceed('user', 'user-1', 20));
    }

    public function testWouldExceedReturnsFalseUnderLimit(): void
    {
        $this->sq->setLimit('user', 'user-1', 100);
        $this->sq->add('user', 'user-1', 50);
        $this->assertFalse($this->sq->wouldExceed('user', 'user-1', 40));
    }

    public function testWouldExceedReturnsFalseWithNoLimit(): void
    {
        $this->sq->add('user', 'user-1', 1000);
        $this->assertFalse($this->sq->wouldExceed('user', 'user-1', 999_999_999));
    }

    // ── percentUsed ───────────────────────────────────────────────────────────

    public function testPercentUsedReturnsCorrectValue(): void
    {
        $this->sq->setLimit('user', 'user-1', 200);
        $this->sq->add('user', 'user-1', 50);
        $this->assertSame(25.0, $this->sq->percentUsed('user', 'user-1'));
    }

    public function testPercentUsedReturnsNullWithoutLimit(): void
    {
        $this->sq->add('user', 'user-1', 100);
        $this->assertNull($this->sq->percentUsed('user', 'user-1'));
    }

    // ── reset ─────────────────────────────────────────────────────────────────

    public function testResetSetsUsageToZero(): void
    {
        $this->sq->setLimit('user', 'user-1', 1000);
        $this->sq->add('user', 'user-1', 500);
        $this->assertTrue($this->sq->reset('user', 'user-1'));
        $info = $this->sq->usage('user', 'user-1');
        $this->assertNotNull($info);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(0, $info['used']);
    }

    public function testResetReturnsFalseForNonExistentOwner(): void
    {
        $this->assertFalse($this->sq->reset('user', 'nobody'));
    }

    // ── isolation ─────────────────────────────────────────────────────────────

    public function testOwnerTypeIsolation(): void
    {
        $this->sq->setLimit('user', 'x', 100);
        $this->sq->setLimit('org', 'x', 500);
        $userInfo = $this->sq->usage('user', 'x');
        $orgInfo  = $this->sq->usage('org', 'x');
        $this->assertNotNull($userInfo);
        $this->assertNotNull($orgInfo);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(100, $userInfo['limit']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(500, $orgInfo['limit']);
    }
}
