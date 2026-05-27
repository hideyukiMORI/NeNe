<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\UserPreference;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for UserPreference.
 */
final class UserPreferenceTest extends TestCase
{
    private PDO $db;
    private UserPreference $up;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE user_preferences (
                user_id    VARCHAR(255) NOT NULL,
                pref_key   VARCHAR(100) NOT NULL,
                pref_value TEXT         NOT NULL DEFAULT \'\',
                updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id, pref_key)
            )
        ');
        $this->up = new UserPreference($this->db);
    }

    // ── set + get ─────────────────────────────────────────────────────────────

    public function testSetAndGet(): void
    {
        $this->up->set('user-1', 'theme', 'dark');
        $this->assertSame('dark', $this->up->get('user-1', 'theme'));
    }

    public function testGetReturnsDefaultWhenNotSet(): void
    {
        $this->assertSame('light', $this->up->get('user-1', 'theme', 'light'));
    }

    public function testGetReturnsEmptyStringDefaultByDefault(): void
    {
        $this->assertSame('', $this->up->get('user-1', 'missing'));
    }

    public function testSetIsUpsert(): void
    {
        $this->up->set('user-1', 'theme', 'dark');
        $this->up->set('user-1', 'theme', 'light');
        $this->assertSame('light', $this->up->get('user-1', 'theme'));
    }

    public function testPreferencesAreScopedToUser(): void
    {
        $this->up->set('user-1', 'theme', 'dark');
        $this->assertSame('light', $this->up->get('user-2', 'theme', 'light'));
    }

    public function testPreferencesAreScopedToKey(): void
    {
        $this->up->set('user-1', 'theme', 'dark');
        $this->assertSame('', $this->up->get('user-1', 'locale'));
    }

    // ── has ───────────────────────────────────────────────────────────────────

    public function testHasReturnsTrueWhenSet(): void
    {
        $this->up->set('user-1', 'theme', 'dark');
        $this->assertTrue($this->up->has('user-1', 'theme'));
    }

    public function testHasReturnsFalseWhenNotSet(): void
    {
        $this->assertFalse($this->up->has('user-1', 'missing'));
    }

    // ── all ───────────────────────────────────────────────────────────────────

    public function testAllReturnsAllPreferences(): void
    {
        $this->up->set('user-1', 'theme', 'dark');
        $this->up->set('user-1', 'locale', 'ja');
        $all = $this->up->all('user-1');
        $this->assertSame(['locale' => 'ja', 'theme' => 'dark'], $all);
    }

    public function testAllReturnsEmptyForUserWithNoPreferences(): void
    {
        $this->assertSame([], $this->up->all('nobody'));
    }

    public function testAllIsUserScoped(): void
    {
        $this->up->set('user-1', 'theme', 'dark');
        $this->up->set('user-2', 'theme', 'light');
        $this->assertCount(1, $this->up->all('user-1'));
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDeleteRemovesKey(): void
    {
        $this->up->set('user-1', 'theme', 'dark');
        $this->assertTrue($this->up->delete('user-1', 'theme'));
        $this->assertFalse($this->up->has('user-1', 'theme'));
    }

    public function testDeleteReturnsFalseIfNotPresent(): void
    {
        $this->assertFalse($this->up->delete('user-1', 'missing'));
    }

    public function testDeleteDoesNotAffectOtherKeys(): void
    {
        $this->up->set('user-1', 'theme', 'dark');
        $this->up->set('user-1', 'locale', 'ja');
        $this->up->delete('user-1', 'theme');
        $this->assertTrue($this->up->has('user-1', 'locale'));
    }

    // ── deleteAll ─────────────────────────────────────────────────────────────

    public function testDeleteAllRemovesAllKeys(): void
    {
        $this->up->set('user-1', 'theme', 'dark');
        $this->up->set('user-1', 'locale', 'ja');
        $this->assertSame(2, $this->up->deleteAll('user-1'));
        $this->assertSame([], $this->up->all('user-1'));
    }

    public function testDeleteAllDoesNotAffectOtherUsers(): void
    {
        $this->up->set('user-1', 'theme', 'dark');
        $this->up->set('user-2', 'theme', 'light');
        $this->up->deleteAll('user-1');
        $this->assertTrue($this->up->has('user-2', 'theme'));
    }

    // ── count ─────────────────────────────────────────────────────────────────

    public function testCountReturnsZeroInitially(): void
    {
        $this->assertSame(0, $this->up->count('user-1'));
    }

    public function testCountReturnsNumberOfStoredPreferences(): void
    {
        $this->up->set('user-1', 'theme', 'dark');
        $this->up->set('user-1', 'locale', 'ja');
        $this->assertSame(2, $this->up->count('user-1'));
    }

    // ── validation ────────────────────────────────────────────────────────────

    public function testSetThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->up->set('', 'theme', 'dark');
    }

    public function testSetThrowsOnEmptyKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->up->set('user-1', '', 'dark');
    }

    public function testGetThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->up->get('', 'theme');
    }
}
