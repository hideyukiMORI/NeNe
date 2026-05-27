<?php

declare(strict_types=1);

namespace Tests\Unit\Xion;

use Nene\Xion\SystemSetting;
use PDO;
use PHPUnit\Framework\TestCase;

final class SystemSettingTest extends TestCase
{
    private PDO $pdo;
    private SystemSetting $ss;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE system_settings (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                key        VARCHAR(100) NOT NULL UNIQUE,
                value      TEXT         NULL,
                type       VARCHAR(10)  NOT NULL DEFAULT \'string\',
                category   VARCHAR(100) NOT NULL DEFAULT \'general\',
                updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->ss = new SystemSetting($this->pdo);
    }

    // ── set / get ─────────────────────────────────────────────────────────────

    public function testSetAndGetString(): void
    {
        $this->ss->set('site.name', 'My App');
        $this->assertSame('My App', $this->ss->getString('site.name'));
    }

    public function testSetAndGetInt(): void
    {
        $this->ss->set('max_upload', '50', 'int');
        $this->assertSame(50, $this->ss->getInt('max_upload'));
    }

    public function testSetAndGetBoolTrue(): void
    {
        foreach (['1', 'true', 'yes', 'on', 'TRUE', 'YES'] as $v) {
            $this->ss->set('flag', $v, 'bool');
            $this->assertTrue($this->ss->getBool('flag'), "Expected true for value '{$v}'");
        }
    }

    public function testSetAndGetBoolFalse(): void
    {
        foreach (['0', 'false', 'no', 'off'] as $v) {
            $this->ss->set('flag', $v, 'bool');
            $this->assertFalse($this->ss->getBool('flag'), "Expected false for value '{$v}'");
        }
    }

    public function testSetAndGetJson(): void
    {
        $this->ss->set('mimes', '["image/png","image/jpeg"]', 'json');
        $this->assertSame(['image/png', 'image/jpeg'], $this->ss->getJson('mimes'));
    }

    public function testGetReturnsNullDefaultForMissing(): void
    {
        $this->assertNull($this->ss->get('missing'));
    }

    public function testGetStringReturnsDefaultForMissing(): void
    {
        $this->assertSame('default', $this->ss->getString('missing', 'default'));
    }

    public function testGetIntReturnsDefaultForMissing(): void
    {
        $this->assertSame(42, $this->ss->getInt('missing', 42));
    }

    public function testGetBoolReturnsDefaultForMissing(): void
    {
        $this->assertTrue($this->ss->getBool('missing', true));
    }

    public function testGetJsonReturnsDefaultForMissing(): void
    {
        $this->assertSame(['fallback'], $this->ss->getJson('missing', ['fallback']));
    }

    // ── upsert ────────────────────────────────────────────────────────────────

    public function testSetIsIdempotentUpsert(): void
    {
        $this->ss->set('key', 'first');
        $this->ss->set('key', 'second');
        $this->assertSame('second', $this->ss->getString('key'));

        $rows = $this->ss->all();
        $this->assertCount(1, $rows);
    }

    public function testSetUpdatesTypeOnUpsert(): void
    {
        $this->ss->set('count', '5', 'string');
        $this->ss->set('count', '10', 'int');
        $this->assertSame(10, $this->ss->getInt('count'));
    }

    // ── validation ────────────────────────────────────────────────────────────

    public function testSetThrowsOnEmptyKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ss->set('', 'value');
    }

    public function testSetThrowsOnUnknownType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ss->set('key', 'value', 'float');
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDeleteRemovesSetting(): void
    {
        $this->ss->set('temp', 'x');
        $result = $this->ss->delete('temp');
        $this->assertTrue($result);
        $this->assertSame('', $this->ss->getString('temp'));
    }

    public function testDeleteReturnsFalseForMissing(): void
    {
        $this->assertFalse($this->ss->delete('missing'));
    }

    // ── allForCategory ────────────────────────────────────────────────────────

    public function testAllForCategoryFiltersCorrectly(): void
    {
        $this->ss->set('upload.max', '50', 'int', 'upload');
        $this->ss->set('upload.types', '["png"]', 'json', 'upload');
        $this->ss->set('site.name', 'App', 'string', 'general');

        $rows = $this->ss->allForCategory('upload');
        $this->assertCount(2, $rows);
    }

    public function testAllForCategoryReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->ss->allForCategory('nonexistent'));
    }

    // ── all ───────────────────────────────────────────────────────────────────

    public function testAllReturnsAllSettings(): void
    {
        $this->ss->set('a', '1', 'string', 'cat1');
        $this->ss->set('b', '2', 'string', 'cat2');
        $this->ss->set('c', '3', 'string', 'cat1');

        $rows = $this->ss->all();
        $this->assertCount(3, $rows);
    }

    public function testAllReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->ss->all());
    }

    // ── get() cast dispatch ───────────────────────────────────────────────────

    public function testGetCastsIntType(): void
    {
        $this->ss->set('n', '7', 'int');
        $result = $this->ss->get('n');
        $this->assertSame(7, $result);
    }

    public function testGetCastsBoolType(): void
    {
        $this->ss->set('b', '1', 'bool');
        $this->assertTrue($this->ss->get('b'));
    }
}
