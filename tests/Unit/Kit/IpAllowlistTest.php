<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\IpAllowlist;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for IpAllowlist.
 */
final class IpAllowlistTest extends TestCase
{
    private PDO $db;
    private IpAllowlist $ia;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE ip_allowlist (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                resource   VARCHAR(255) NOT NULL,
                cidr       VARCHAR(50)  NOT NULL,
                label      VARCHAR(255) NOT NULL DEFAULT \'\',
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (resource, cidr)
            )
        ');
        $this->ia = new IpAllowlist($this->db);
    }

    // ── add ───────────────────────────────────────────────────────────────────

    public function testAddExactIp(): void
    {
        $this->ia->add('admin', '10.0.0.1');
        $this->assertTrue($this->ia->has('admin', '10.0.0.1'));
    }

    public function testAddCidrRange(): void
    {
        $this->ia->add('admin', '192.168.1.0/24');
        $this->assertTrue($this->ia->has('admin', '192.168.1.0/24'));
    }

    public function testAddIsIdempotent(): void
    {
        $this->ia->add('admin', '10.0.0.1');
        $this->ia->add('admin', '10.0.0.1'); // no exception
        $this->assertSame(1, $this->ia->count('admin'));
    }

    public function testAddThrowsOnEmptyResource(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ia->add('', '10.0.0.1');
    }

    public function testAddThrowsOnInvalidCidr(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ia->add('admin', 'not-an-ip');
    }

    public function testAddThrowsOnInvalidCidrPrefix(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ia->add('admin', '10.0.0.0/99');
    }

    // ── remove ────────────────────────────────────────────────────────────────

    public function testRemoveDeletesEntry(): void
    {
        $this->ia->add('admin', '10.0.0.1');
        $this->assertTrue($this->ia->remove('admin', '10.0.0.1'));
        $this->assertFalse($this->ia->has('admin', '10.0.0.1'));
    }

    public function testRemoveReturnsFalseIfNotFound(): void
    {
        $this->assertFalse($this->ia->remove('admin', '10.0.0.1'));
    }

    // ── isAllowed ─────────────────────────────────────────────────────────────

    public function testIsAllowedExactMatch(): void
    {
        $this->ia->add('admin', '10.0.0.1');
        $this->assertTrue($this->ia->isAllowed('admin', '10.0.0.1'));
    }

    public function testIsAllowedCidrMatch(): void
    {
        $this->ia->add('admin', '192.168.1.0/24');
        $this->assertTrue($this->ia->isAllowed('admin', '192.168.1.100'));
        $this->assertTrue($this->ia->isAllowed('admin', '192.168.1.1'));
        $this->assertFalse($this->ia->isAllowed('admin', '192.168.2.1'));
    }

    public function testIsAllowedReturnsFalseWhenEmptyList(): void
    {
        $this->assertFalse($this->ia->isAllowed('admin', '1.2.3.4'));
    }

    public function testIsAllowedReturnsFalseForUnlistedIp(): void
    {
        $this->ia->add('admin', '10.0.0.1');
        $this->assertFalse($this->ia->isAllowed('admin', '10.0.0.2'));
    }

    public function testIsAllowedThrowsOnInvalidIp(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ia->isAllowed('admin', 'not-an-ip');
    }

    public function testIsAllowedIsResourceScoped(): void
    {
        $this->ia->add('admin', '10.0.0.1');
        $this->assertFalse($this->ia->isAllowed('api', '10.0.0.1'));
    }

    public function testIsAllowedSlash32(): void
    {
        $this->ia->add('admin', '10.0.0.1/32');
        $this->assertTrue($this->ia->isAllowed('admin', '10.0.0.1'));
        $this->assertFalse($this->ia->isAllowed('admin', '10.0.0.2'));
    }

    // ── list ──────────────────────────────────────────────────────────────────

    public function testListReturnsAllEntries(): void
    {
        $this->ia->add('admin', '10.0.0.1');
        $this->ia->add('admin', '10.0.0.2');
        $this->assertCount(2, $this->ia->list('admin'));
    }

    public function testListReturnsEmptyForUnknownResource(): void
    {
        $this->assertSame([], $this->ia->list('nothing'));
    }

    // ── count ─────────────────────────────────────────────────────────────────

    public function testCountReturnsCorrectCount(): void
    {
        $this->ia->add('admin', '10.0.0.1');
        $this->ia->add('admin', '10.0.0.2');
        $this->assertSame(2, $this->ia->count('admin'));
    }

    // ── clear ─────────────────────────────────────────────────────────────────

    public function testClearDeletesAllEntries(): void
    {
        $this->ia->add('admin', '10.0.0.1');
        $this->ia->add('admin', '10.0.0.2');
        $this->assertSame(2, $this->ia->clear('admin'));
        $this->assertSame(0, $this->ia->count('admin'));
    }

    public function testClearDoesNotAffectOtherResources(): void
    {
        $this->ia->add('admin', '10.0.0.1');
        $this->ia->add('api', '10.0.0.1');
        $this->ia->clear('admin');
        $this->assertSame(1, $this->ia->count('api'));
    }
}
