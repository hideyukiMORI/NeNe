<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\ApiKey;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ApiKey using an in-memory SQLite database.
 */
final class ApiKeyTest extends TestCase
{
    private PDO $db;
    private ApiKey $manager;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec(
            'CREATE TABLE api_keys (
                id         INTEGER      PRIMARY KEY AUTOINCREMENT,
                prefix     VARCHAR(16)  NOT NULL,
                key_hash   VARCHAR(64)  NOT NULL UNIQUE,
                owner_id   VARCHAR(255) NOT NULL,
                scope      VARCHAR(32)  NOT NULL DEFAULT \'read\',
                label      VARCHAR(255) NOT NULL DEFAULT \'\',
                expires_at DATETIME     DEFAULT NULL,
                revoked_at DATETIME     DEFAULT NULL,
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $this->manager = new ApiKey($this->db);
    }

    // ── create ───────────────────────────────────────────────────────────────

    public function testCreateReturnsRawKeyAndId(): void
    {
        $result = $this->manager->create('user:1');
        $this->assertArrayHasKey('rawKey', $result);
        $this->assertArrayHasKey('id', $result);
        $this->assertIsString($result['rawKey']);
        $this->assertIsInt($result['id']);
    }

    public function testCreatedKeyStartsWithPrefix(): void
    {
        $result = $this->manager->create('user:1');
        $this->assertStringStartsWith('nk_', $result['rawKey']);
    }

    public function testCreateWithExplicitScope(): void
    {
        $result = $this->manager->create('user:1', scope: 'admin');
        $keys   = $this->manager->list('user:1');
        $this->assertSame('admin', $keys[0]['scope']);
    }

    public function testCreateWithLabel(): void
    {
        $this->manager->create('user:1', label: 'CI key');
        $keys = $this->manager->list('user:1');
        $this->assertSame('CI key', $keys[0]['label']);
    }

    public function testCreateWithExpiryStoresExpiresAt(): void
    {
        $this->manager->create('user:1', expiresIn: 3600);
        $keys = $this->manager->list('user:1');
        $this->assertNotNull($keys[0]['expires_at']);
    }

    public function testCreateWithInvalidScopeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->manager->create('user:1', scope: 'superadmin');
    }

    // ── authenticate ─────────────────────────────────────────────────────────

    public function testAuthenticateValidKeyReturnsRecord(): void
    {
        ['rawKey' => $raw] = $this->manager->create('user:1', scope: 'write');
        $result = $this->manager->authenticate($raw, 'write');
        $this->assertIsArray($result);
        $this->assertSame('user:1', $result['owner_id']);
        $this->assertSame('write', $result['scope']);
    }

    public function testAuthenticateEmptyKeyReturnsNull(): void
    {
        $this->assertNull($this->manager->authenticate('', 'read'));
    }

    public function testAuthenticateInvalidKeyReturnsNull(): void
    {
        $this->manager->create('user:1');
        $this->assertNull($this->manager->authenticate('nk_invalidkeyvalue', 'read'));
    }

    public function testAuthenticateRevokedKeyReturnsNull(): void
    {
        ['rawKey' => $raw, 'id' => $id] = $this->manager->create('user:1');
        $this->manager->revoke($id, 'user:1');
        $this->assertNull($this->manager->authenticate($raw, 'read'));
    }

    public function testAuthenticateExpiredKeyReturnsNull(): void
    {
        ['rawKey' => $raw, 'id' => $id] = $this->manager->create('user:1', expiresIn: 3600);
        // Force expires_at into the past
        $this->db->exec("UPDATE api_keys SET expires_at = '2000-01-01 00:00:00' WHERE id = {$id}");
        $this->assertNull($this->manager->authenticate($raw, 'read'));
    }

    public function testKeyHashNeverExposedInAuthResult(): void
    {
        ['rawKey' => $raw] = $this->manager->create('user:1');
        $result = $this->manager->authenticate($raw, 'read');
        $this->assertIsArray($result);
        $this->assertArrayNotHasKey('key_hash', $result);
    }

    // ── scope enforcement ─────────────────────────────────────────────────────

    public function testReadKeySatisfiesReadScope(): void
    {
        ['rawKey' => $raw] = $this->manager->create('user:1', scope: 'read');
        $this->assertNotNull($this->manager->authenticate($raw, 'read'));
    }

    public function testReadKeyFailsWriteScope(): void
    {
        ['rawKey' => $raw] = $this->manager->create('user:1', scope: 'read');
        $this->assertNull($this->manager->authenticate($raw, 'write'));
    }

    public function testWriteKeySatisfiesReadScope(): void
    {
        ['rawKey' => $raw] = $this->manager->create('user:1', scope: 'write');
        $this->assertNotNull($this->manager->authenticate($raw, 'read'));
    }

    public function testAdminKeySatisfiesAllScopes(): void
    {
        ['rawKey' => $raw] = $this->manager->create('user:1', scope: 'admin');
        $this->assertNotNull($this->manager->authenticate($raw, 'read'));
        $this->assertNotNull($this->manager->authenticate($raw, 'write'));
        $this->assertNotNull($this->manager->authenticate($raw, 'admin'));
    }

    public function testWriteKeyFailsAdminScope(): void
    {
        ['rawKey' => $raw] = $this->manager->create('user:1', scope: 'write');
        $this->assertNull($this->manager->authenticate($raw, 'admin'));
    }

    // ── list ─────────────────────────────────────────────────────────────────

    public function testListReturnsOnlyOwnerKeys(): void
    {
        $this->manager->create('user:1');
        $this->manager->create('user:2');
        $keys = $this->manager->list('user:1');
        $this->assertCount(1, $keys);
    }

    public function testListExcludesRevokedKeys(): void
    {
        ['id' => $id] = $this->manager->create('user:1');
        $this->manager->revoke($id, 'user:1');
        $this->assertEmpty($this->manager->list('user:1'));
    }

    public function testListDoesNotExposeKeyHash(): void
    {
        $this->manager->create('user:1');
        $keys = $this->manager->list('user:1');
        $this->assertArrayNotHasKey('key_hash', $keys[0]);
    }

    // ── revoke ────────────────────────────────────────────────────────────────

    public function testRevokeByCorrectOwnerReturnsTrue(): void
    {
        ['id' => $id] = $this->manager->create('user:1');
        $this->assertTrue($this->manager->revoke($id, 'user:1'));
    }

    public function testRevokeByWrongOwnerReturnsFalse(): void
    {
        ['id' => $id] = $this->manager->create('user:1');
        $this->assertFalse($this->manager->revoke($id, 'user:999'));
    }

    public function testRevokeAlreadyRevokedReturnsFalse(): void
    {
        ['id' => $id] = $this->manager->create('user:1');
        $this->manager->revoke($id, 'user:1');
        $this->assertFalse($this->manager->revoke($id, 'user:1'));
    }

    // ── rotate ────────────────────────────────────────────────────────────────

    public function testRotateReturnsNewKey(): void
    {
        ['rawKey' => $old, 'id' => $id] = $this->manager->create('user:1', scope: 'write');
        $result = $this->manager->rotate($id, 'user:1');
        $this->assertIsArray($result);
        $this->assertNotSame($old, $result['rawKey']);
    }

    public function testRotateRevokesOldKey(): void
    {
        ['rawKey' => $old, 'id' => $id] = $this->manager->create('user:1');
        $this->manager->rotate($id, 'user:1');
        $this->assertNull($this->manager->authenticate($old, 'read'));
    }

    public function testRotateNewKeyInheritsScope(): void
    {
        ['id' => $id] = $this->manager->create('user:1', scope: 'admin');
        ['rawKey' => $newRaw] = $this->manager->rotate($id, 'user:1');
        $result = $this->manager->authenticate($newRaw, 'admin');
        $this->assertSame('admin', $result['scope']);
    }

    public function testRotateWrongOwnerReturnsNull(): void
    {
        ['id' => $id] = $this->manager->create('user:1');
        $this->assertNull($this->manager->rotate($id, 'user:999'));
    }
}
