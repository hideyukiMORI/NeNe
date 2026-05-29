<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\PersonalAccessToken;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PersonalAccessToken using an in-memory SQLite database.
 */
final class PersonalAccessTokenTest extends TestCase
{
    private PDO $db;
    private PersonalAccessToken $pat;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec(
            'CREATE TABLE personal_access_tokens (
                id           INTEGER      PRIMARY KEY AUTOINCREMENT,
                prefix       VARCHAR(16)  NOT NULL,
                token_hash   VARCHAR(64)  NOT NULL UNIQUE,
                user_id      VARCHAR(255) NOT NULL,
                name         VARCHAR(255) NOT NULL DEFAULT \'\',
                abilities    TEXT         NOT NULL DEFAULT \'*\',
                expires_at   DATETIME     DEFAULT NULL,
                last_used_at DATETIME     DEFAULT NULL,
                revoked_at   DATETIME     DEFAULT NULL,
                created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $this->pat = new PersonalAccessToken($this->db);
    }

    // ── create ────────────────────────────────────────────────────────────────

    public function testCreateReturnsRawTokenAndId(): void
    {
        $result = $this->pat->create('user:1');
        $this->assertArrayHasKey('rawToken', $result);
        $this->assertArrayHasKey('id', $result);
        $this->assertIsString($result['rawToken']);
        $this->assertIsInt($result['id']);
    }

    public function testCreatedTokenStartsWithPat(): void
    {
        $result = $this->pat->create('user:1');
        $this->assertStringStartsWith('pat_', $result['rawToken']);
    }

    public function testCreateWithName(): void
    {
        $this->pat->create('user:1', 'CI deploy');
        $list = $this->pat->list('user:1');
        $this->assertSame('CI deploy', $list[0]['name']);
    }

    public function testCreateWithAbilitiesArray(): void
    {
        $this->pat->create('user:1', 'deploy', ['read', 'write']);
        $list = $this->pat->list('user:1');
        $this->assertSame('["read","write"]', $list[0]['abilities']);
    }

    public function testCreateWithWildcardAbility(): void
    {
        $this->pat->create('user:1', 'admin', '*');
        $list = $this->pat->list('user:1');
        $this->assertSame('*', $list[0]['abilities']);
    }

    public function testCreateWithExpiryStoresExpiresAt(): void
    {
        $this->pat->create('user:1', expiresIn: 3600);
        $list = $this->pat->list('user:1');
        $this->assertNotNull($list[0]['expires_at']);
    }

    // ── authenticate ──────────────────────────────────────────────────────────

    public function testAuthenticateValidTokenReturnsRecord(): void
    {
        ['rawToken' => $raw] = $this->pat->create('user:1', 'my-key', ['read']);
        $result = $this->pat->authenticate($raw, 'read');
        $this->assertIsArray($result);
        $this->assertSame('user:1', $result['user_id']);
    }

    public function testAuthenticateEmptyTokenReturnsNull(): void
    {
        $this->assertNull($this->pat->authenticate('', 'read'));
    }

    public function testAuthenticateInvalidTokenReturnsNull(): void
    {
        $this->pat->create('user:1');
        $this->assertNull($this->pat->authenticate('pat_invalidtoken', 'read'));
    }

    public function testAuthenticateRevokedTokenReturnsNull(): void
    {
        ['rawToken' => $raw, 'id' => $id] = $this->pat->create('user:1');
        $this->pat->revoke($id, 'user:1');
        $this->assertNull($this->pat->authenticate($raw, '*'));
    }

    public function testAuthenticateExpiredTokenReturnsNull(): void
    {
        ['rawToken' => $raw, 'id' => $id] = $this->pat->create('user:1', expiresIn: 3600);
        $this->db->exec("UPDATE personal_access_tokens SET expires_at = '2000-01-01 00:00:00' WHERE id = {$id}");
        $this->assertNull($this->pat->authenticate($raw, '*'));
    }

    public function testAuthenticateUpdatesLastUsedAt(): void
    {
        ['rawToken' => $raw, 'id' => $id] = $this->pat->create('user:1');
        // last_used_at should be null before first use
        $stmt = $this->db->query("SELECT last_used_at FROM personal_access_tokens WHERE id = {$id}");
        $this->assertNull($stmt->fetchColumn());

        $this->pat->authenticate($raw, '*');
        $stmt = $this->db->query("SELECT last_used_at FROM personal_access_tokens WHERE id = {$id}");
        $this->assertNotNull($stmt->fetchColumn());
    }

    public function testTokenHashNeverExposedInAuthResult(): void
    {
        ['rawToken' => $raw] = $this->pat->create('user:1');
        $result = $this->pat->authenticate($raw, '*');
        $this->assertNotNull($result);
        // @phan-suppress-next-line PhanTypeMismatchArgumentNullable
        $this->assertArrayNotHasKey('token_hash', $result);
    }

    // ── ability check ─────────────────────────────────────────────────────────

    public function testWildcardAbilitySatisfiesAnyRequirement(): void
    {
        ['rawToken' => $raw] = $this->pat->create('user:1', abilities: '*');
        $this->assertNotNull($this->pat->authenticate($raw, 'read'));
        $this->assertNotNull($this->pat->authenticate($raw, 'write'));
        $this->assertNotNull($this->pat->authenticate($raw, 'deploy'));
    }

    public function testSpecificAbilitySatisfiesExactMatch(): void
    {
        ['rawToken' => $raw] = $this->pat->create('user:1', abilities: ['read']);
        $this->assertNotNull($this->pat->authenticate($raw, 'read'));
    }

    public function testSpecificAbilityFailsMissingAbility(): void
    {
        ['rawToken' => $raw] = $this->pat->create('user:1', abilities: ['read']);
        $this->assertNull($this->pat->authenticate($raw, 'write'));
    }

    public function testWildcardInAbilitiesArraySatisfiesAll(): void
    {
        ['rawToken' => $raw] = $this->pat->create('user:1', abilities: ['*']);
        $this->assertNotNull($this->pat->authenticate($raw, 'deploy'));
    }

    // ── list ──────────────────────────────────────────────────────────────────

    public function testListReturnsOnlyOwnerTokens(): void
    {
        $this->pat->create('user:1');
        $this->pat->create('user:2');
        $this->assertCount(1, $this->pat->list('user:1'));
    }

    public function testListExcludesRevokedTokens(): void
    {
        ['id' => $id] = $this->pat->create('user:1');
        $this->pat->revoke($id, 'user:1');
        $this->assertEmpty($this->pat->list('user:1'));
    }

    public function testListDoesNotExposeTokenHash(): void
    {
        $this->pat->create('user:1');
        $list = $this->pat->list('user:1');
        $this->assertArrayNotHasKey('token_hash', $list[0]);
    }

    public function testListInitialLastUsedAtIsNull(): void
    {
        $this->pat->create('user:1');
        $list = $this->pat->list('user:1');
        $this->assertNull($list[0]['last_used_at']);
    }

    // ── revoke ────────────────────────────────────────────────────────────────

    public function testRevokeByCorrectOwnerReturnsTrue(): void
    {
        ['id' => $id] = $this->pat->create('user:1');
        $this->assertTrue($this->pat->revoke($id, 'user:1'));
    }

    public function testRevokeByWrongOwnerReturnsFalse(): void
    {
        ['id' => $id] = $this->pat->create('user:1');
        $this->assertFalse($this->pat->revoke($id, 'user:999'));
    }

    public function testRevokeAlreadyRevokedReturnsFalse(): void
    {
        ['id' => $id] = $this->pat->create('user:1');
        $this->pat->revoke($id, 'user:1');
        $this->assertFalse($this->pat->revoke($id, 'user:1'));
    }
}
