<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\TenantConfig;
use PDO;
use PHPUnit\Framework\TestCase;

final class TenantConfigTest extends TestCase
{
    private PDO $pdo;
    private TenantConfig $tc;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE tenant_configs (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id  VARCHAR(255) NOT NULL,
                config_key VARCHAR(100) NOT NULL,
                value      TEXT         NULL,
                updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (tenant_id, config_key)
            )
        ');
        $this->tc = new TenantConfig($this->pdo);
    }

    // ── set / get ─────────────────────────────────────────────────────────────

    public function testSetAndGetValue(): void
    {
        $this->tc->set('tenant-abc', 'max_seats', '50');
        $this->assertSame('50', $this->tc->get('tenant-abc', 'max_seats'));
    }

    public function testSetUpdatesExistingKey(): void
    {
        $this->tc->set('tenant-abc', 'max_seats', '10');
        $this->tc->set('tenant-abc', 'max_seats', '99');
        $this->assertSame('99', $this->tc->get('tenant-abc', 'max_seats'));
    }

    public function testGetReturnsDefaultWhenNotSet(): void
    {
        $this->assertSame('fallback', $this->tc->get('tenant-abc', 'nonexistent', 'fallback'));
    }

    public function testGetReturnsNullDefaultWhenNotSetAndNoDefault(): void
    {
        $this->assertNull($this->tc->get('tenant-abc', 'nonexistent'));
    }

    public function testSetNullValue(): void
    {
        $this->tc->set('tenant-abc', 'opt_key', null);
        $this->assertNull($this->tc->get('tenant-abc', 'opt_key'));
    }

    public function testSetThrowsOnEmptyTenantId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->tc->set('', 'key', 'value');
    }

    public function testSetThrowsOnEmptyKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->tc->set('tenant-abc', '', 'value');
    }

    // ── getInt ────────────────────────────────────────────────────────────────

    public function testGetIntConvertsString(): void
    {
        $this->tc->set('tenant-abc', 'max_seats', '50');
        $this->assertSame(50, $this->tc->getInt('tenant-abc', 'max_seats'));
    }

    public function testGetIntReturnsDefaultWhenNotSet(): void
    {
        $this->assertSame(10, $this->tc->getInt('tenant-abc', 'nonexistent', 10));
    }

    // ── getBool ───────────────────────────────────────────────────────────────

    public function testGetBoolTrueForTrueString(): void
    {
        $this->tc->set('tenant-abc', 'feature_on', 'true');
        $this->assertTrue($this->tc->getBool('tenant-abc', 'feature_on'));
    }

    public function testGetBoolTrueForOneString(): void
    {
        $this->tc->set('tenant-abc', 'feature_on', '1');
        $this->assertTrue($this->tc->getBool('tenant-abc', 'feature_on'));
    }

    public function testGetBoolTrueForYesString(): void
    {
        $this->tc->set('tenant-abc', 'feature_on', 'yes');
        $this->assertTrue($this->tc->getBool('tenant-abc', 'feature_on'));
    }

    public function testGetBoolFalseForFalseString(): void
    {
        $this->tc->set('tenant-abc', 'feature_on', 'false');
        $this->assertFalse($this->tc->getBool('tenant-abc', 'feature_on'));
    }

    public function testGetBoolReturnsDefaultWhenNotSet(): void
    {
        $this->assertTrue($this->tc->getBool('tenant-abc', 'nonexistent', true));
    }

    // ── getJson ───────────────────────────────────────────────────────────────

    public function testGetJsonDecodesArray(): void
    {
        $this->tc->set('tenant-abc', 'limits', '{"api":100,"storage":500}');
        $result = $this->tc->getJson('tenant-abc', 'limits');
        $this->assertSame(['api' => 100, 'storage' => 500], $result);
    }

    public function testGetJsonReturnsDefaultForInvalidJson(): void
    {
        $this->tc->set('tenant-abc', 'limits', 'not-json');
        $this->assertSame([], $this->tc->getJson('tenant-abc', 'limits', []));
    }

    public function testGetJsonReturnsDefaultWhenNotSet(): void
    {
        $this->assertNull($this->tc->getJson('tenant-abc', 'nonexistent'));
    }

    // ── has ───────────────────────────────────────────────────────────────────

    public function testHasReturnsTrueWhenSet(): void
    {
        $this->tc->set('tenant-abc', 'key', 'value');
        $this->assertTrue($this->tc->has('tenant-abc', 'key'));
    }

    public function testHasReturnsFalseWhenNotSet(): void
    {
        $this->assertFalse($this->tc->has('tenant-abc', 'nonexistent'));
    }

    public function testHasTrueForNullValue(): void
    {
        $this->tc->set('tenant-abc', 'key', null);
        $this->assertTrue($this->tc->has('tenant-abc', 'key'));
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDeleteRemovesKey(): void
    {
        $this->tc->set('tenant-abc', 'key', 'value');
        $this->assertTrue($this->tc->delete('tenant-abc', 'key'));
        $this->assertFalse($this->tc->has('tenant-abc', 'key'));
    }

    public function testDeleteReturnsFalseWhenNotExists(): void
    {
        $this->assertFalse($this->tc->delete('tenant-abc', 'nonexistent'));
    }

    // ── all ───────────────────────────────────────────────────────────────────

    public function testAllReturnsKeyValueMap(): void
    {
        $this->tc->set('tenant-abc', 'a', '1');
        $this->tc->set('tenant-abc', 'b', '2');
        $this->tc->set('tenant-xyz', 'a', '99'); // different tenant
        $all = $this->tc->all('tenant-abc');
        $this->assertSame(['a' => '1', 'b' => '2'], $all);
    }

    public function testAllReturnsEmptyForUnknownTenant(): void
    {
        $this->assertSame([], $this->tc->all('tenant-unknown'));
    }

    // ── purge ─────────────────────────────────────────────────────────────────

    public function testPurgeDeletesAllKeysForTenant(): void
    {
        $this->tc->set('tenant-abc', 'a', '1');
        $this->tc->set('tenant-abc', 'b', '2');
        $this->tc->set('tenant-xyz', 'a', '99');
        $deleted = $this->tc->purge('tenant-abc');
        $this->assertSame(2, $deleted);
        $this->assertSame([], $this->tc->all('tenant-abc'));
        $this->assertSame(['a' => '99'], $this->tc->all('tenant-xyz'));
    }

    // ── copyTo ────────────────────────────────────────────────────────────────

    public function testCopyToCopiesAllKeys(): void
    {
        $this->tc->set('tenant-abc', 'x', '10');
        $this->tc->set('tenant-abc', 'y', '20');
        $count = $this->tc->copyTo('tenant-abc', 'tenant-new');
        $this->assertSame(2, $count);
        $this->assertSame('10', $this->tc->get('tenant-new', 'x'));
        $this->assertSame('20', $this->tc->get('tenant-new', 'y'));
    }

    public function testCopyToOverwritesExistingKeys(): void
    {
        $this->tc->set('tenant-src', 'key', 'new-value');
        $this->tc->set('tenant-dst', 'key', 'old-value');
        $this->tc->copyTo('tenant-src', 'tenant-dst');
        $this->assertSame('new-value', $this->tc->get('tenant-dst', 'key'));
    }
}
