<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\NotificationPreference;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for NotificationPreference.
 */
final class NotificationPreferenceTest extends TestCase
{
    private PDO $db;
    private NotificationPreference $prefs;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE notification_preferences (
                user_id    VARCHAR(255) NOT NULL,
                channel    VARCHAR(50)  NOT NULL,
                type       VARCHAR(100) NOT NULL,
                enabled    TINYINT(1)   NOT NULL DEFAULT 1,
                updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id, channel, type)
            )
        ');
        $this->prefs = new NotificationPreference($this->db);
    }

    // ── isEnabled default ─────────────────────────────────────────────────────

    public function testIsEnabledDefaultsToTrue(): void
    {
        // No record stored — should default to opted-in
        $this->assertTrue($this->prefs->isEnabled('user-1', 'email', 'marketing'));
    }

    // ── setEnabled + isEnabled ────────────────────────────────────────────────

    public function testSetEnabledFalseOptsOut(): void
    {
        $this->prefs->setEnabled('user-1', 'email', 'marketing', false);
        $this->assertFalse($this->prefs->isEnabled('user-1', 'email', 'marketing'));
    }

    public function testSetEnabledTrueOptsIn(): void
    {
        $this->prefs->setEnabled('user-1', 'email', 'marketing', false);
        $this->prefs->setEnabled('user-1', 'email', 'marketing', true);
        $this->assertTrue($this->prefs->isEnabled('user-1', 'email', 'marketing'));
    }

    public function testSetEnabledIsUpsert(): void
    {
        $this->prefs->setEnabled('user-1', 'email', 'marketing', false);
        $this->prefs->setEnabled('user-1', 'email', 'marketing', false); // second call should not throw
        $this->assertFalse($this->prefs->isEnabled('user-1', 'email', 'marketing'));
    }

    public function testPreferencesAreUserScoped(): void
    {
        $this->prefs->setEnabled('user-1', 'email', 'marketing', false);
        $this->assertTrue($this->prefs->isEnabled('user-2', 'email', 'marketing'));
    }

    public function testPreferencesAreChannelScoped(): void
    {
        $this->prefs->setEnabled('user-1', 'email', 'marketing', false);
        $this->assertTrue($this->prefs->isEnabled('user-1', 'push', 'marketing'));
    }

    public function testPreferencesAreTypeScoped(): void
    {
        $this->prefs->setEnabled('user-1', 'email', 'marketing', false);
        $this->assertTrue($this->prefs->isEnabled('user-1', 'email', 'order_update'));
    }

    public function testSetEnabledThrowsOnEmptyChannel(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->prefs->setEnabled('user-1', '', 'marketing', true);
    }

    public function testSetEnabledThrowsOnEmptyType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->prefs->setEnabled('user-1', 'email', '', true);
    }

    // ── all ───────────────────────────────────────────────────────────────────

    public function testAllReturnsStoredPreferences(): void
    {
        $this->prefs->setEnabled('user-1', 'email', 'marketing', false);
        $this->prefs->setEnabled('user-1', 'push', 'order', true);

        $all = $this->prefs->all('user-1');
        $this->assertCount(2, $all);
    }

    public function testAllReturnsCorrectShape(): void
    {
        $this->prefs->setEnabled('user-1', 'email', 'marketing', false);
        $all = $this->prefs->all('user-1');
        $this->assertArrayHasKey('channel', $all[0]);
        $this->assertArrayHasKey('type', $all[0]);
        $this->assertArrayHasKey('enabled', $all[0]);
        $this->assertFalse($all[0]['enabled']);
    }

    public function testAllReturnsEmptyForUserWithNoPrefs(): void
    {
        $this->assertSame([], $this->prefs->all('nobody'));
    }

    public function testAllIsUserScoped(): void
    {
        $this->prefs->setEnabled('user-1', 'email', 'marketing', false);
        $this->prefs->setEnabled('user-2', 'push', 'alerts', false);
        $this->assertCount(1, $this->prefs->all('user-1'));
    }

    // ── reset ─────────────────────────────────────────────────────────────────

    public function testResetDeletesRow(): void
    {
        $this->prefs->setEnabled('user-1', 'email', 'marketing', false);
        $this->assertTrue($this->prefs->reset('user-1', 'email', 'marketing'));
        // After reset, defaults to opted-in
        $this->assertTrue($this->prefs->isEnabled('user-1', 'email', 'marketing'));
    }

    public function testResetReturnsFalseIfRowNotPresent(): void
    {
        $this->assertFalse($this->prefs->reset('user-1', 'email', 'marketing'));
    }

    // ── resetAll ──────────────────────────────────────────────────────────────

    public function testResetAllDeletesAllRows(): void
    {
        $this->prefs->setEnabled('user-1', 'email', 'marketing', false);
        $this->prefs->setEnabled('user-1', 'push', 'alerts', false);
        $this->assertSame(2, $this->prefs->resetAll('user-1'));
        $this->assertSame([], $this->prefs->all('user-1'));
    }

    public function testResetAllDoesNotAffectOtherUsers(): void
    {
        $this->prefs->setEnabled('user-1', 'email', 'marketing', false);
        $this->prefs->setEnabled('user-2', 'email', 'marketing', false);
        $this->prefs->resetAll('user-1');
        $this->assertCount(1, $this->prefs->all('user-2'));
    }
}
