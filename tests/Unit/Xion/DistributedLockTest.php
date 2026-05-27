<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\DistributedLock;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DistributedLock using an in-memory SQLite database.
 */
final class DistributedLockTest extends TestCase
{
    private PDO $db;
    private DistributedLock $lock;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec(
            'CREATE TABLE distributed_locks (
                resource    VARCHAR(255) PRIMARY KEY,
                owner       VARCHAR(255) NOT NULL,
                expires_at  DATETIME     NOT NULL,
                acquired_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $this->lock = new DistributedLock($this->db);
    }

    // ── acquire ──────────────────────────────────────────────────────────────

    public function testAcquireNewResource(): void
    {
        $result = $this->lock->acquire('job:report', 'owner-A', 30);
        $this->assertTrue($result);
    }

    public function testAcquireSameOwnerIsIdempotent(): void
    {
        $this->lock->acquire('job:report', 'owner-A', 30);
        $result = $this->lock->acquire('job:report', 'owner-A', 30);
        $this->assertTrue($result);
    }

    public function testAcquireContestedLockReturnsFalse(): void
    {
        $this->lock->acquire('job:report', 'owner-A', 30);
        $result = $this->lock->acquire('job:report', 'owner-B', 30);
        $this->assertFalse($result);
    }

    public function testAcquireStaleLockIsReclaimed(): void
    {
        // Insert an already-expired lock directly
        $this->db->exec(
            "INSERT INTO distributed_locks (resource, owner, expires_at, acquired_at)
             VALUES ('job:report', 'owner-A', '2000-01-01 00:00:00', '2000-01-01 00:00:00')"
        );

        $result = $this->lock->acquire('job:report', 'owner-B', 30);
        $this->assertTrue($result);
    }

    public function testAcquireMultipleDistinctResources(): void
    {
        $this->assertTrue($this->lock->acquire('res:alpha', 'owner-A', 30));
        $this->assertTrue($this->lock->acquire('res:beta', 'owner-B', 30));
    }

    // ── release ──────────────────────────────────────────────────────────────

    public function testReleaseByCorrectOwnerReturnsTrue(): void
    {
        $this->lock->acquire('job:report', 'owner-A', 30);
        $result = $this->lock->release('job:report', 'owner-A');
        $this->assertTrue($result);
    }

    public function testReleaseByWrongOwnerReturnsFalse(): void
    {
        $this->lock->acquire('job:report', 'owner-A', 30);
        $result = $this->lock->release('job:report', 'owner-B');
        $this->assertFalse($result);
    }

    public function testReleaseNonExistentResourceReturnsFalse(): void
    {
        $result = $this->lock->release('job:nonexistent', 'owner-A');
        $this->assertFalse($result);
    }

    public function testAfterReleaseNewOwnerCanAcquire(): void
    {
        $this->lock->acquire('job:report', 'owner-A', 30);
        $this->lock->release('job:report', 'owner-A');
        $result = $this->lock->acquire('job:report', 'owner-B', 30);
        $this->assertTrue($result);
    }

    // ── renew ─────────────────────────────────────────────────────────────────

    public function testRenewByCorrectOwnerReturnsTrue(): void
    {
        $this->lock->acquire('job:report', 'owner-A', 30);
        $result = $this->lock->renew('job:report', 'owner-A', 60);
        $this->assertTrue($result);
    }

    public function testRenewByWrongOwnerReturnsFalse(): void
    {
        $this->lock->acquire('job:report', 'owner-A', 30);
        $result = $this->lock->renew('job:report', 'owner-B', 60);
        $this->assertFalse($result);
    }

    public function testRenewExpiredLockReturnsFalse(): void
    {
        // Insert an already-expired lock directly
        $this->db->exec(
            "INSERT INTO distributed_locks (resource, owner, expires_at, acquired_at)
             VALUES ('job:report', 'owner-A', '2000-01-01 00:00:00', '2000-01-01 00:00:00')"
        );

        $result = $this->lock->renew('job:report', 'owner-A', 30);
        $this->assertFalse($result);
    }

    public function testRenewNonExistentResourceReturnsFalse(): void
    {
        $result = $this->lock->renew('job:nonexistent', 'owner-A', 30);
        $this->assertFalse($result);
    }

    // ── isHeld ───────────────────────────────────────────────────────────────

    public function testIsHeldReturnsTrueWhenLockActive(): void
    {
        $this->lock->acquire('job:report', 'owner-A', 30);
        $this->assertTrue($this->lock->isHeld('job:report'));
    }

    public function testIsHeldReturnsFalseWhenNoLock(): void
    {
        $this->assertFalse($this->lock->isHeld('job:report'));
    }

    public function testIsHeldReturnsFalseAfterRelease(): void
    {
        $this->lock->acquire('job:report', 'owner-A', 30);
        $this->lock->release('job:report', 'owner-A');
        $this->assertFalse($this->lock->isHeld('job:report'));
    }

    public function testIsHeldReturnsFalseForExpiredLock(): void
    {
        // Insert an already-expired lock directly
        $this->db->exec(
            "INSERT INTO distributed_locks (resource, owner, expires_at, acquired_at)
             VALUES ('job:report', 'owner-A', '2000-01-01 00:00:00', '2000-01-01 00:00:00')"
        );

        $this->assertFalse($this->lock->isHeld('job:report'));
    }
}
