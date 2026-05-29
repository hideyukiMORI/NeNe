<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\ConfigStore;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ConfigStore.
 */
final class ConfigStoreTest extends TestCase
{
    private PDO $db;
    private ConfigStore $cfg;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE config_store (
                config_key   VARCHAR(255) NOT NULL PRIMARY KEY,
                config_value TEXT         NOT NULL DEFAULT \'\',
                updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->cfg = new ConfigStore($this->db);
    }

    // ── set / get ─────────────────────────────────────────────────────────────

    public function testSetAndGet(): void
    {
        $this->cfg->set('app.name', 'MyApp');
        $this->assertSame('MyApp', $this->cfg->get('app.name'));
    }

    public function testGetReturnsDefaultWhenMissing(): void
    {
        $this->assertSame('', $this->cfg->get('missing'));
        $this->assertSame('fallback', $this->cfg->get('missing', 'fallback'));
    }

    public function testSetIsUpsert(): void
    {
        $this->cfg->set('x', 'a');
        $this->cfg->set('x', 'b');
        $this->assertSame('b', $this->cfg->get('x'));
        $this->assertSame(1, $this->cfg->count());
    }

    public function testSetThrowsOnEmptyKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cfg->set('', 'value');
    }

    // ── getInt ────────────────────────────────────────────────────────────────

    public function testGetInt(): void
    {
        $this->cfg->set('limit', '42');
        $this->assertSame(42, $this->cfg->getInt('limit'));
    }

    public function testGetIntReturnsDefaultWhenMissing(): void
    {
        $this->assertSame(0, $this->cfg->getInt('missing'));
        $this->assertSame(99, $this->cfg->getInt('missing', 99));
    }

    // ── getBool ───────────────────────────────────────────────────────────────

    public function testGetBoolTrueValues(): void
    {
        foreach (['1', 'true', 'yes', 'on', 'TRUE', 'YES', 'ON'] as $i => $val) {
            $this->cfg->set("k{$i}", $val);
            $this->assertTrue($this->cfg->getBool("k{$i}"), "Expected true for '{$val}'");
        }
    }

    public function testGetBoolFalseValues(): void
    {
        $this->cfg->set('f', '0');
        $this->assertFalse($this->cfg->getBool('f'));
        $this->cfg->set('f2', 'false');
        $this->assertFalse($this->cfg->getBool('f2'));
    }

    public function testGetBoolReturnsDefaultWhenMissing(): void
    {
        $this->assertFalse($this->cfg->getBool('missing'));
        $this->assertTrue($this->cfg->getBool('missing', true));
    }

    // ── has ───────────────────────────────────────────────────────────────────

    public function testHas(): void
    {
        $this->assertFalse($this->cfg->has('x'));
        $this->cfg->set('x', 'v');
        $this->assertTrue($this->cfg->has('x'));
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDelete(): void
    {
        $this->cfg->set('x', 'v');
        $this->assertTrue($this->cfg->delete('x'));
        $this->assertFalse($this->cfg->has('x'));
    }

    public function testDeleteReturnsFalseWhenMissing(): void
    {
        $this->assertFalse($this->cfg->delete('nope'));
    }

    // ── all ───────────────────────────────────────────────────────────────────

    public function testAll(): void
    {
        $this->cfg->set('b', '2');
        $this->cfg->set('a', '1');
        $all = $this->cfg->all();
        $this->assertSame(['a' => '1', 'b' => '2'], $all);
    }

    public function testAllReturnsEmptyWhenNoKeys(): void
    {
        $this->assertSame([], $this->cfg->all());
    }

    // ── namespace ─────────────────────────────────────────────────────────────

    public function testNamespaceReturnsMatchingKeys(): void
    {
        $this->cfg->set('mail.from', 'a@b.com');
        $this->cfg->set('mail.host', 'smtp.example.com');
        $this->cfg->set('app.name', 'MyApp');
        $ns = $this->cfg->namespace('mail');
        $this->assertSame(['mail.from' => 'a@b.com', 'mail.host' => 'smtp.example.com'], $ns);
    }

    public function testNamespaceReturnsEmptyWhenNoneMatch(): void
    {
        $this->cfg->set('app.name', 'x');
        $this->assertSame([], $this->cfg->namespace('mail'));
    }

    // ── deleteNamespace ───────────────────────────────────────────────────────

    public function testDeleteNamespace(): void
    {
        $this->cfg->set('mail.from', 'a@b.com');
        $this->cfg->set('mail.host', 'smtp.example.com');
        $this->cfg->set('app.name', 'MyApp');
        $deleted = $this->cfg->deleteNamespace('mail');
        $this->assertSame(2, $deleted);
        $this->assertTrue($this->cfg->has('app.name'));
        $this->assertFalse($this->cfg->has('mail.from'));
    }

    public function testDeleteNamespaceReturnsZeroWhenNoneMatch(): void
    {
        $this->assertSame(0, $this->cfg->deleteNamespace('nope'));
    }

    // ── count ─────────────────────────────────────────────────────────────────

    public function testCount(): void
    {
        $this->assertSame(0, $this->cfg->count());
        $this->cfg->set('a', '1');
        $this->cfg->set('b', '2');
        $this->assertSame(2, $this->cfg->count());
    }
}
