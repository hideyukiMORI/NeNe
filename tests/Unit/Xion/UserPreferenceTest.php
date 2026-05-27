<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\UserPreference;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for UserPreference using an in-memory SQLite database.
 */
final class UserPreferenceTest extends TestCase
{
    private PDO $db;
    private UserPreference $prefs;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec(
            'CREATE TABLE user_preferences (
                user_id    VARCHAR(255) NOT NULL,
                pref_key   VARCHAR(255) NOT NULL,
                pref_value TEXT         NOT NULL,
                updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id, pref_key)
            )'
        );
        $this->prefs = new UserPreference($this->db);
    }

    // ── get ───────────────────────────────────────────────────────────────────

    public function testGetReturnsStoredValue(): void
    {
        $this->prefs->set('user:1', 'theme', 'dark');
        $this->assertSame('dark', $this->prefs->get('user:1', 'theme'));
    }

    public function testGetReturnsNullDefaultWhenKeyNotSet(): void
    {
        $this->assertNull($this->prefs->get('user:1', 'theme'));
    }

    public function testGetReturnsCustomDefaultWhenKeyNotSet(): void
    {
        $this->assertSame('light', $this->prefs->get('user:1', 'theme', 'light'));
    }

    public function testGetIsUserIsolated(): void
    {
        $this->prefs->set('user:1', 'theme', 'dark');
        $this->assertNull($this->prefs->get('user:2', 'theme'));
    }

    // ── set ───────────────────────────────────────────────────────────────────

    public function testSetOverwritesExistingValue(): void
    {
        $this->prefs->set('user:1', 'theme', 'dark');
        $this->prefs->set('user:1', 'theme', 'light');
        $this->assertSame('light', $this->prefs->get('user:1', 'theme'));
    }

    public function testSetMultipleKeys(): void
    {
        $this->prefs->set('user:1', 'theme', 'dark');
        $this->prefs->set('user:1', 'lang', 'ja');
        $this->assertSame('dark', $this->prefs->get('user:1', 'theme'));
        $this->assertSame('ja', $this->prefs->get('user:1', 'lang'));
    }

    // ── getInt ────────────────────────────────────────────────────────────────

    public function testGetIntReturnsStoredValueAsInt(): void
    {
        $this->prefs->set('user:1', 'per_page', '20');
        $this->assertSame(20, $this->prefs->getInt('user:1', 'per_page'));
    }

    public function testGetIntReturnsDefaultWhenKeyNotSet(): void
    {
        $this->assertSame(10, $this->prefs->getInt('user:1', 'per_page', 10));
    }

    public function testGetIntDefaultIsZeroWhenNotSpecified(): void
    {
        $this->assertSame(0, $this->prefs->getInt('user:1', 'per_page'));
    }

    // ── getBool ───────────────────────────────────────────────────────────────

    public function testGetBoolReturnsTrueFor1(): void
    {
        $this->prefs->set('user:1', 'notif', '1');
        $this->assertTrue($this->prefs->getBool('user:1', 'notif'));
    }

    public function testGetBoolReturnsTrueForTrue(): void
    {
        $this->prefs->set('user:1', 'notif', 'true');
        $this->assertTrue($this->prefs->getBool('user:1', 'notif'));
    }

    public function testGetBoolReturnsTrueForYes(): void
    {
        $this->prefs->set('user:1', 'notif', 'yes');
        $this->assertTrue($this->prefs->getBool('user:1', 'notif'));
    }

    public function testGetBoolReturnsFalseFor0(): void
    {
        $this->prefs->set('user:1', 'notif', '0');
        $this->assertFalse($this->prefs->getBool('user:1', 'notif'));
    }

    public function testGetBoolReturnsDefaultWhenKeyNotSet(): void
    {
        $this->assertTrue($this->prefs->getBool('user:1', 'notif', true));
        $this->assertFalse($this->prefs->getBool('user:1', 'notif', false));
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDeleteReturnsTrueWhenKeyExists(): void
    {
        $this->prefs->set('user:1', 'theme', 'dark');
        $this->assertTrue($this->prefs->delete('user:1', 'theme'));
    }

    public function testDeleteReturnsFalseWhenKeyNotSet(): void
    {
        $this->assertFalse($this->prefs->delete('user:1', 'theme'));
    }

    public function testDeleteRevertsToDefault(): void
    {
        $this->prefs->set('user:1', 'theme', 'dark');
        $this->prefs->delete('user:1', 'theme');
        $this->assertSame('light', $this->prefs->get('user:1', 'theme', 'light'));
    }

    // ── all ───────────────────────────────────────────────────────────────────

    public function testAllReturnsKeyValueMap(): void
    {
        $this->prefs->set('user:1', 'theme', 'dark');
        $this->prefs->set('user:1', 'lang', 'ja');
        $all = $this->prefs->all('user:1');
        $this->assertSame('dark', $all['theme']);
        $this->assertSame('ja', $all['lang']);
    }

    public function testAllReturnsEmptyMapWhenNoPrefs(): void
    {
        $this->assertSame([], $this->prefs->all('user:1'));
    }

    public function testAllIsUserIsolated(): void
    {
        $this->prefs->set('user:1', 'theme', 'dark');
        $this->assertSame([], $this->prefs->all('user:2'));
    }
}
