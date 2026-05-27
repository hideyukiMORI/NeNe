<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\IpBlocklist;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for IpBlocklist.
 */
final class IpBlocklistTest extends TestCase
{
    private PDO $db;
    private IpBlocklist $bl;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE ip_blocklist (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                ip         VARCHAR(45)  NOT NULL UNIQUE,
                reason     VARCHAR(255) NOT NULL DEFAULT \'\',
                blocked_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expires_at DATETIME     DEFAULT NULL
            )
        ');
        $this->bl = new IpBlocklist($this->db);
    }

    // ── block ─────────────────────────────────────────────────────────────────

    public function testBlockReturnsId(): void
    {
        $id = $this->bl->block('1.2.3.4', 'spam');
        $this->assertGreaterThan(0, $id);
    }

    public function testBlockIsUpsert(): void
    {
        $this->bl->block('1.2.3.4', 'spam');
        $this->bl->block('1.2.3.4', 'brute-force');
        $row = $this->bl->find('1.2.3.4');
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('brute-force', $row['reason']);
        $this->assertSame(1, $this->bl->count());
    }

    public function testBlockThrowsOnEmptyIp(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->bl->block('');
    }

    public function testBlockWithExpiry(): void
    {
        $exp = new \DateTimeImmutable('+1 hour');
        $this->bl->block('1.2.3.4', 'test', $exp);
        $this->assertTrue($this->bl->isBlocked('1.2.3.4'));
    }

    // ── unblock ───────────────────────────────────────────────────────────────

    public function testUnblock(): void
    {
        $this->bl->block('1.2.3.4');
        $this->assertTrue($this->bl->unblock('1.2.3.4'));
        $this->assertFalse($this->bl->isBlocked('1.2.3.4'));
    }

    public function testUnblockReturnsFalseWhenNotBlocked(): void
    {
        $this->assertFalse($this->bl->unblock('9.9.9.9'));
    }

    // ── isBlocked ─────────────────────────────────────────────────────────────

    public function testIsBlockedReturnsTrueForBlockedIp(): void
    {
        $this->bl->block('1.2.3.4');
        $this->assertTrue($this->bl->isBlocked('1.2.3.4'));
    }

    public function testIsBlockedReturnsFalseForUnknownIp(): void
    {
        $this->assertFalse($this->bl->isBlocked('9.9.9.9'));
    }

    public function testIsBlockedReturnsFalseForExpiredBlock(): void
    {
        $this->db->exec(
            "INSERT INTO ip_blocklist (ip, reason, expires_at)
             VALUES ('5.5.5.5', 'old', '2000-01-01 00:00:00')"
        );
        $this->assertFalse($this->bl->isBlocked('5.5.5.5'));
    }

    public function testIsBlockedReturnsTrueForNonExpiredBlock(): void
    {
        $this->db->exec(
            "INSERT INTO ip_blocklist (ip, reason, expires_at)
             VALUES ('6.6.6.6', 'temp', '2099-01-01 00:00:00')"
        );
        $this->assertTrue($this->bl->isBlocked('6.6.6.6'));
    }

    // ── find ──────────────────────────────────────────────────────────────────

    public function testFindReturnsActiveBlock(): void
    {
        $this->bl->block('1.2.3.4', 'spam');
        $row = $this->bl->find('1.2.3.4');
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('1.2.3.4', $row['ip']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('spam', $row['reason']);
    }

    public function testFindReturnsNullForUnknown(): void
    {
        $this->assertNull($this->bl->find('9.9.9.9'));
    }

    public function testFindReturnsNullForExpired(): void
    {
        $this->db->exec(
            "INSERT INTO ip_blocklist (ip, reason, expires_at)
             VALUES ('7.7.7.7', 'old', '2000-01-01 00:00:00')"
        );
        $this->assertNull($this->bl->find('7.7.7.7'));
    }

    // ── all ───────────────────────────────────────────────────────────────────

    public function testAllReturnsActiveOnly(): void
    {
        $this->bl->block('1.1.1.1');
        $this->bl->block('2.2.2.2');
        $this->db->exec(
            "INSERT INTO ip_blocklist (ip, reason, expires_at)
             VALUES ('3.3.3.3', 'old', '2000-01-01 00:00:00')"
        );
        $all = $this->bl->all();
        $this->assertCount(2, $all);
    }

    // ── purgeExpired ──────────────────────────────────────────────────────────

    public function testPurgeExpiredRemovesOnlyExpiredRows(): void
    {
        $this->bl->block('1.1.1.1');
        $this->db->exec(
            "INSERT INTO ip_blocklist (ip, reason, expires_at)
             VALUES ('2.2.2.2', 'old', '2000-01-01 00:00:00')"
        );
        $deleted = $this->bl->purgeExpired();
        $this->assertSame(1, $deleted);
        $this->assertTrue($this->bl->isBlocked('1.1.1.1'));
    }

    // ── count ─────────────────────────────────────────────────────────────────

    public function testCount(): void
    {
        $this->assertSame(0, $this->bl->count());
        $this->bl->block('1.1.1.1');
        $this->bl->block('2.2.2.2');
        $this->assertSame(2, $this->bl->count());
    }

    public function testCountExcludesExpired(): void
    {
        $this->db->exec(
            "INSERT INTO ip_blocklist (ip, reason, expires_at)
             VALUES ('3.3.3.3', 'old', '2000-01-01 00:00:00')"
        );
        $this->assertSame(0, $this->bl->count());
    }
}
