<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\MaintenanceMode;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MaintenanceMode.
 */
final class MaintenanceModeTest extends TestCase
{
    private PDO $db;
    private MaintenanceMode $mm;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE maintenance_mode (
                key        VARCHAR(50)  NOT NULL PRIMARY KEY,
                value      TEXT         NOT NULL DEFAULT \'\',
                updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE maintenance_allowlist (
                user_id  VARCHAR(255) NOT NULL PRIMARY KEY,
                added_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            );
        ');
        $this->mm = new MaintenanceMode($this->db);
    }

    // ── enable / disable / isActive ───────────────────────────────────────────

    public function testDefaultIsInactive(): void
    {
        $this->assertFalse($this->mm->isActive());
    }

    public function testEnableSetsActive(): void
    {
        $this->mm->enable();
        $this->assertTrue($this->mm->isActive());
    }

    public function testDisableClears(): void
    {
        $this->mm->enable();
        $this->mm->disable();
        $this->assertFalse($this->mm->isActive());
    }

    public function testEnableIsIdempotent(): void
    {
        $this->mm->enable();
        $this->mm->enable('Updated message');
        $this->assertTrue($this->mm->isActive());
    }

    // ── status ────────────────────────────────────────────────────────────────

    public function testStatusReturnsAllFields(): void
    {
        $this->mm->enable('Deploying v2', '2026-12-01 03:00:00');
        $s = $this->mm->status();
        $this->assertTrue($s['active']);
        $this->assertSame('Deploying v2', $s['message']);
        $this->assertSame('2026-12-01 03:00:00', $s['scheduled_end']);
    }

    public function testStatusWhenInactiveHasEmptyFields(): void
    {
        $s = $this->mm->status();
        $this->assertFalse($s['active']);
        $this->assertSame('', $s['message']);
        $this->assertSame('', $s['scheduled_end']);
    }

    // ── allow / disallow / isAllowed ──────────────────────────────────────────

    public function testIsAllowedReturnsTrueWhenInactive(): void
    {
        // Maintenance is off — all users are allowed
        $this->assertTrue($this->mm->isAllowed('user-1'));
    }

    public function testIsAllowedReturnsFalseForUnlistedUserWhenActive(): void
    {
        $this->mm->enable();
        $this->assertFalse($this->mm->isAllowed('user-1'));
    }

    public function testAllowWhitelistsUser(): void
    {
        $this->mm->enable();
        $this->mm->allow('admin-1');
        $this->assertTrue($this->mm->isAllowed('admin-1'));
    }

    public function testAllowIsIdempotent(): void
    {
        $this->mm->allow('admin-1');
        $this->mm->allow('admin-1'); // should not throw
        $this->assertCount(1, $this->mm->allowlist());
    }

    public function testDisallowRemovesUser(): void
    {
        $this->mm->enable();
        $this->mm->allow('admin-1');
        $this->assertTrue($this->mm->disallow('admin-1'));
        $this->assertFalse($this->mm->isAllowed('admin-1'));
    }

    public function testDisallowReturnsFalseIfNotOnList(): void
    {
        $this->assertFalse($this->mm->disallow('nobody'));
    }

    // ── allowlist ─────────────────────────────────────────────────────────────

    public function testAllowlistReturnsAllEntries(): void
    {
        $this->mm->allow('admin-1');
        $this->mm->allow('admin-2');
        $list = $this->mm->allowlist();
        $this->assertContains('admin-1', $list);
        $this->assertContains('admin-2', $list);
        $this->assertCount(2, $list);
    }

    public function testAllowlistIsEmptyInitially(): void
    {
        $this->assertSame([], $this->mm->allowlist());
    }
}
