<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\FeatureFlag;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for FeatureFlag.
 */
final class FeatureFlagTest extends TestCase
{
    private PDO $db;
    private FeatureFlag $ff;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE feature_flags (
                flag_name  VARCHAR(255) NOT NULL PRIMARY KEY,
                enabled    TINYINT(1)   NOT NULL DEFAULT 0,
                updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->db->exec('
            CREATE TABLE feature_flag_overrides (
                flag_name VARCHAR(255) NOT NULL,
                user_id   VARCHAR(255) NOT NULL,
                enabled   TINYINT(1)   NOT NULL DEFAULT 1,
                PRIMARY KEY (flag_name, user_id)
            )
        ');
        $this->ff = new FeatureFlag($this->db);
    }

    // ── isEnabled (global) ────────────────────────────────────────────────────

    public function testIsEnabledReturnsFalseForUnknownFlag(): void
    {
        $this->assertFalse($this->ff->isEnabled('unknown-flag'));
    }

    public function testEnableSetsGlobalToTrue(): void
    {
        $this->ff->enable('dark-mode');
        $this->assertTrue($this->ff->isEnabled('dark-mode'));
    }

    public function testDisableSetsGlobalToFalse(): void
    {
        $this->ff->enable('dark-mode');
        $this->ff->disable('dark-mode');
        $this->assertFalse($this->ff->isEnabled('dark-mode'));
    }

    public function testEnableIsUpsert(): void
    {
        $this->ff->enable('dark-mode');
        $this->ff->enable('dark-mode'); // second call should not throw
        $this->assertTrue($this->ff->isEnabled('dark-mode'));
    }

    public function testDisableCreatesFlag(): void
    {
        $this->ff->disable('new-flag');
        $this->assertFalse($this->ff->isEnabled('new-flag'));
    }

    // ── isEnabledForUser — fallback to global ─────────────────────────────────

    public function testIsEnabledForUserFallsBackToGlobal(): void
    {
        $this->ff->enable('dark-mode');
        $this->assertTrue($this->ff->isEnabledForUser('dark-mode', 'user-1'));
    }

    public function testIsEnabledForUserReturnsFalseWhenGlobalDisabled(): void
    {
        $this->ff->disable('dark-mode');
        $this->assertFalse($this->ff->isEnabledForUser('dark-mode', 'user-1'));
    }

    public function testIsEnabledForUserReturnsFalseForUnknownFlag(): void
    {
        $this->assertFalse($this->ff->isEnabledForUser('unknown', 'user-1'));
    }

    // ── enableForUser / disableForUser ────────────────────────────────────────

    public function testEnableForUserOverridesGlobalDisabled(): void
    {
        $this->ff->disable('beta');
        $this->ff->enableForUser('beta', 'user-1');
        $this->assertTrue($this->ff->isEnabledForUser('beta', 'user-1'));
    }

    public function testDisableForUserOverridesGlobalEnabled(): void
    {
        $this->ff->enable('beta');
        $this->ff->disableForUser('beta', 'user-1');
        $this->assertFalse($this->ff->isEnabledForUser('beta', 'user-1'));
    }

    public function testOverrideDoesNotAffectOtherUsers(): void
    {
        $this->ff->enable('beta');
        $this->ff->disableForUser('beta', 'user-1');
        $this->assertTrue($this->ff->isEnabledForUser('beta', 'user-2'));
    }

    public function testOverrideIsUpsert(): void
    {
        $this->ff->enableForUser('beta', 'user-1');
        $this->ff->disableForUser('beta', 'user-1'); // update in place
        $this->assertFalse($this->ff->isEnabledForUser('beta', 'user-1'));
    }

    // ── removeOverride ────────────────────────────────────────────────────────

    public function testRemoveOverrideFallsBackToGlobal(): void
    {
        $this->ff->enable('beta');
        $this->ff->disableForUser('beta', 'user-1');
        $this->ff->removeOverride('beta', 'user-1');
        $this->assertTrue($this->ff->isEnabledForUser('beta', 'user-1'));
    }

    public function testRemoveOverrideReturnsTrueIfRemoved(): void
    {
        $this->ff->enableForUser('beta', 'user-1');
        $this->assertTrue($this->ff->removeOverride('beta', 'user-1'));
    }

    public function testRemoveOverrideReturnsFalseIfNotPresent(): void
    {
        $this->assertFalse($this->ff->removeOverride('beta', 'user-999'));
    }

    // ── all ───────────────────────────────────────────────────────────────────

    public function testAllReturnsAllFlags(): void
    {
        $this->ff->enable('alpha');
        $this->ff->disable('beta');
        $all = $this->ff->all();
        $this->assertCount(2, $all);
    }

    public function testAllReturnsCorrectShape(): void
    {
        $this->ff->enable('dark-mode');
        $all = $this->ff->all();
        $this->assertArrayHasKey('flag_name', $all[0]);
        $this->assertArrayHasKey('enabled', $all[0]);
        $this->assertSame('dark-mode', $all[0]['flag_name']);
        $this->assertTrue($all[0]['enabled']);
    }

    public function testAllReturnsEmptyWhenNoFlags(): void
    {
        $this->assertSame([], $this->ff->all());
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDeleteRemovesFlag(): void
    {
        $this->ff->enable('to-delete');
        $this->assertTrue($this->ff->delete('to-delete'));
        $this->assertFalse($this->ff->isEnabled('to-delete'));
    }

    public function testDeleteRemovesOverrides(): void
    {
        $this->ff->enable('to-delete');
        $this->ff->enableForUser('to-delete', 'user-1');
        $this->ff->delete('to-delete');
        $this->assertSame(0, $this->ff->overrideCount('to-delete'));
    }

    public function testDeleteReturnsFalseIfNotFound(): void
    {
        $this->assertFalse($this->ff->delete('nonexistent'));
    }

    // ── overrideCount ─────────────────────────────────────────────────────────

    public function testOverrideCountReturnsZeroInitially(): void
    {
        $this->assertSame(0, $this->ff->overrideCount('beta'));
    }

    public function testOverrideCountIncrementsOnOverride(): void
    {
        $this->ff->enableForUser('beta', 'user-1');
        $this->ff->enableForUser('beta', 'user-2');
        $this->assertSame(2, $this->ff->overrideCount('beta'));
    }

    // ── validation ────────────────────────────────────────────────────────────

    public function testEnableThrowsOnEmptyFlagName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ff->enable('');
    }

    public function testDisableThrowsOnEmptyFlagName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ff->disable('');
    }

    public function testIsEnabledForUserThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ff->isEnabledForUser('beta', '');
    }

    public function testEnableForUserThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ff->enableForUser('beta', '');
    }
}
