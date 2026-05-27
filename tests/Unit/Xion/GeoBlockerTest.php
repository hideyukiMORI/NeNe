<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\GeoBlocker;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for GeoBlocker.
 */
final class GeoBlockerTest extends TestCase
{
    private PDO $db;
    private GeoBlocker $gb;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE geo_blocker_settings (
                key_name  VARCHAR(50)  NOT NULL PRIMARY KEY,
                value     VARCHAR(255) NOT NULL
            )
        ');
        $this->db->exec('
            CREATE TABLE geo_blocker_countries (
                country_code CHAR(2)      NOT NULL PRIMARY KEY,
                list_type    VARCHAR(10)  NOT NULL
            )
        ');
        $this->gb = new GeoBlocker($this->db);
    }

    // ── mode ──────────────────────────────────────────────────────────────────

    public function testDefaultModeIsBlocklist(): void
    {
        $this->assertSame('blocklist', $this->gb->getMode());
    }

    public function testSetModeAllowlist(): void
    {
        $this->gb->setMode('allowlist');
        $this->assertSame('allowlist', $this->gb->getMode());
    }

    public function testSetModeBlocklist(): void
    {
        $this->gb->setMode('allowlist');
        $this->gb->setMode('blocklist');
        $this->assertSame('blocklist', $this->gb->getMode());
    }

    public function testSetModeThrowsOnInvalidMode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->gb->setMode('whitelist');
    }

    // ── blocklist mode ────────────────────────────────────────────────────────

    public function testBlocklistModeAllowsUnknownCountry(): void
    {
        // Default mode: blocklist; no entries — everything allowed
        $this->assertTrue($this->gb->isAllowed('JP'));
    }

    public function testBlocklistModeBlocksExplicitCountry(): void
    {
        $this->gb->block('CN');
        $this->assertFalse($this->gb->isAllowed('CN'));
    }

    public function testBlocklistModeAllowsNonBlockedCountry(): void
    {
        $this->gb->block('CN');
        $this->assertTrue($this->gb->isAllowed('JP'));
    }

    public function testBlocklistModeCountryCodeIsCaseInsensitive(): void
    {
        $this->gb->block('cn');
        $this->assertFalse($this->gb->isAllowed('CN'));
        $this->assertFalse($this->gb->isAllowed('cn'));
    }

    // ── allowlist mode ────────────────────────────────────────────────────────

    public function testAllowlistModeBlocksUnknownCountry(): void
    {
        $this->gb->setMode('allowlist');
        $this->assertFalse($this->gb->isAllowed('JP'));
    }

    public function testAllowlistModeAllowsExplicitCountry(): void
    {
        $this->gb->setMode('allowlist');
        $this->gb->allow('JP');
        $this->assertTrue($this->gb->isAllowed('JP'));
    }

    public function testAllowlistModeBlocksNonAllowedCountry(): void
    {
        $this->gb->setMode('allowlist');
        $this->gb->allow('JP');
        $this->assertFalse($this->gb->isAllowed('US'));
    }

    // ── allow / block — validation ────────────────────────────────────────────

    public function testBlockThrowsOnInvalidCode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->gb->block('USA'); // 3 letters
    }

    public function testAllowThrowsOnEmptyCode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->gb->allow('');
    }

    // ── remove ────────────────────────────────────────────────────────────────

    public function testRemoveDeletesEntry(): void
    {
        $this->gb->block('CN');
        $this->assertTrue($this->gb->remove('CN'));
        $this->assertTrue($this->gb->isAllowed('CN')); // back to default (allowed in blocklist)
    }

    public function testRemoveReturnsFalseIfNotPresent(): void
    {
        $this->assertFalse($this->gb->remove('DE'));
    }

    // ── allowList / blockList ─────────────────────────────────────────────────

    public function testBlockListReturnsBlockedCodes(): void
    {
        $this->gb->block('CN');
        $this->gb->block('RU');
        $this->assertSame(['CN', 'RU'], $this->gb->blockList());
    }

    public function testAllowListReturnsAllowedCodes(): void
    {
        $this->gb->setMode('allowlist');
        $this->gb->allow('JP');
        $this->gb->allow('US');
        $this->assertSame(['JP', 'US'], $this->gb->allowList());
    }

    // ── clear ─────────────────────────────────────────────────────────────────

    public function testClearAllRemovesAllEntries(): void
    {
        $this->gb->block('CN');
        $this->gb->allow('JP');
        $this->gb->clear('all');
        $this->assertSame([], $this->gb->blockList());
        $this->assertSame([], $this->gb->allowList());
    }

    public function testClearBlockRemovesOnlyBlockedEntries(): void
    {
        $this->gb->block('CN');
        $this->gb->allow('JP');
        $this->gb->clear('block');
        $this->assertSame([], $this->gb->blockList());
        $this->assertSame(['JP'], $this->gb->allowList());
    }
}
