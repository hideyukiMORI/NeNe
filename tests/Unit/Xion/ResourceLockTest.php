<?php

declare(strict_types=1);

namespace Tests\Unit\Xion;

use Nene\Xion\ResourceLock;
use PDO;
use PHPUnit\Framework\TestCase;

final class ResourceLockTest extends TestCase
{
    private PDO $pdo;
    private ResourceLock $rl;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE resource_locks (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                resource_type VARCHAR(100) NOT NULL,
                resource_id   VARCHAR(255) NOT NULL,
                holder_id     VARCHAR(255) NOT NULL,
                expires_at    DATETIME     NOT NULL,
                created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (resource_type, resource_id)
            )
        ');
        $this->rl = new ResourceLock($this->pdo);
    }

    // ── acquire ───────────────────────────────────────────────────────────────

    public function testAcquireReturnsIdOnSuccess(): void
    {
        $id = $this->rl->acquire('doc', '1', 'user-1', 60);
        $this->assertGreaterThan(0, $id);
    }

    public function testAcquireReturnsNullWhenHeldByOther(): void
    {
        $this->rl->acquire('doc', '1', 'user-1', 60);
        $result = $this->rl->acquire('doc', '1', 'user-2', 60);
        $this->assertNull($result);
    }

    public function testAcquireRenewsOwnLock(): void
    {
        $id1 = $this->rl->acquire('doc', '1', 'user-1', 60);
        $id2 = $this->rl->acquire('doc', '1', 'user-1', 120);
        $this->assertSame($id1, $id2);
    }

    public function testAcquireThrowsOnEmptyResourceType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->rl->acquire('', '1', 'user-1');
    }

    public function testAcquireThrowsOnEmptyResourceId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->rl->acquire('doc', '', 'user-1');
    }

    public function testAcquireThrowsOnEmptyHolderId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->rl->acquire('doc', '1', '');
    }

    public function testAcquireThrowsOnNonPositiveTtl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->rl->acquire('doc', '1', 'user-1', 0);
    }

    // ── current ───────────────────────────────────────────────────────────────

    public function testCurrentReturnsLockWhenActive(): void
    {
        $id = $this->rl->acquire('doc', '1', 'user-1', 60);
        $lock = $this->rl->current('doc', '1');
        $this->assertNotNull($lock);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('user-1', $lock['holder_id']);
    }

    public function testCurrentReturnsNullWhenNoLock(): void
    {
        $this->assertNull($this->rl->current('doc', '1'));
    }

    public function testCurrentReturnsNullForExpiredLock(): void
    {
        // Insert already-expired lock directly
        $past = (new \DateTimeImmutable())->modify('-1 second')->format('Y-m-d H:i:s');
        $this->pdo->exec("INSERT INTO resource_locks (resource_type, resource_id, holder_id, expires_at) VALUES ('doc', '1', 'user-1', '{$past}')");
        $this->assertNull($this->rl->current('doc', '1'));
    }

    // ── isHeldBy ──────────────────────────────────────────────────────────────

    public function testIsHeldByReturnsTrueForHolder(): void
    {
        $this->rl->acquire('doc', '1', 'user-1', 60);
        $this->assertTrue($this->rl->isHeldBy('doc', '1', 'user-1'));
    }

    public function testIsHeldByReturnsFalseForOtherUser(): void
    {
        $this->rl->acquire('doc', '1', 'user-1', 60);
        $this->assertFalse($this->rl->isHeldBy('doc', '1', 'user-2'));
    }

    public function testIsHeldByReturnsFalseWhenNoLock(): void
    {
        $this->assertFalse($this->rl->isHeldBy('doc', '1', 'user-1'));
    }

    // ── release ───────────────────────────────────────────────────────────────

    public function testReleaseRemovesLock(): void
    {
        $this->rl->acquire('doc', '1', 'user-1', 60);
        $result = $this->rl->release('doc', '1', 'user-1');
        $this->assertTrue($result);
        $this->assertNull($this->rl->current('doc', '1'));
    }

    public function testReleaseReturnsFalseForWrongHolder(): void
    {
        $this->rl->acquire('doc', '1', 'user-1', 60);
        $this->assertFalse($this->rl->release('doc', '1', 'user-2'));
    }

    public function testReleaseReturnsFalseWhenNoLock(): void
    {
        $this->assertFalse($this->rl->release('doc', '1', 'user-1'));
    }

    // ── forceRelease ──────────────────────────────────────────────────────────

    public function testForceReleaseRemovesAnyLock(): void
    {
        $id = $this->rl->acquire('doc', '1', 'user-1', 60);
        $this->assertNotNull($id);
        // @phan-suppress-next-line PhanTypeMismatchArgumentNullable
        $this->assertTrue($this->rl->forceRelease($id));
        $this->assertNull($this->rl->current('doc', '1'));
    }

    public function testForceReleaseReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->rl->forceRelease(9999));
    }

    // ── extend ────────────────────────────────────────────────────────────────

    public function testExtendUpdatesExpiry(): void
    {
        $id = $this->rl->acquire('doc', '1', 'user-1', 60);
        $this->assertNotNull($id);
        // @phan-suppress-next-line PhanTypeMismatchArgumentNullable
        $this->assertTrue($this->rl->extend($id, 'user-1', 3600));
        $lock = $this->rl->current('doc', '1');
        $this->assertNotNull($lock);
    }

    public function testExtendReturnsFalseForWrongHolder(): void
    {
        $id = $this->rl->acquire('doc', '1', 'user-1', 60);
        $this->assertNotNull($id);
        // @phan-suppress-next-line PhanTypeMismatchArgumentNullable
        $this->assertFalse($this->rl->extend($id, 'user-2', 3600));
    }

    // ── releaseExpired ────────────────────────────────────────────────────────

    public function testReleaseExpiredDeletesOnlyExpiredLocks(): void
    {
        $this->rl->acquire('doc', '1', 'user-1', 60); // active
        $past = (new \DateTimeImmutable())->modify('-1 second')->format('Y-m-d H:i:s');
        $this->pdo->exec("INSERT INTO resource_locks (resource_type, resource_id, holder_id, expires_at) VALUES ('doc', '2', 'user-2', '{$past}')");

        $count = $this->rl->releaseExpired();
        $this->assertSame(1, $count);
        $this->assertNotNull($this->rl->current('doc', '1'));
    }

    public function testReleaseExpiredReturnsZeroWhenNone(): void
    {
        $this->assertSame(0, $this->rl->releaseExpired());
    }

    // ── acquire after release ─────────────────────────────────────────────────

    public function testAcquireSucceedsAfterRelease(): void
    {
        $this->rl->acquire('doc', '1', 'user-1', 60);
        $this->rl->release('doc', '1', 'user-1');
        $id = $this->rl->acquire('doc', '1', 'user-2', 60);
        $this->assertNotNull($id);
    }
}
