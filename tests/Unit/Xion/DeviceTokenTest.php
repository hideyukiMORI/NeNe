<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\DeviceToken;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DeviceToken.
 */
final class DeviceTokenTest extends TestCase
{
    private PDO $db;
    private DeviceToken $dt;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE device_tokens (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id      VARCHAR(255) NOT NULL,
                token_hash   VARCHAR(64)  NOT NULL UNIQUE,
                label        VARCHAR(255) NOT NULL DEFAULT \'\',
                expires_at   DATETIME     NOT NULL,
                last_used_at DATETIME     DEFAULT NULL,
                created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->dt = new DeviceToken($this->db);
    }

    // ── issue ─────────────────────────────────────────────────────────────────

    public function testIssueReturnsRawToken(): void
    {
        $raw = $this->dt->issue('user-1');
        $this->assertIsString($raw);
        $this->assertSame(64, strlen($raw)); // 32 bytes = 64 hex chars
    }

    public function testIssueStoresHashNotRaw(): void
    {
        $raw  = $this->dt->issue('user-1');
        $hash = hash('sha256', $raw);
        $stmt = $this->db->prepare('SELECT token_hash FROM device_tokens WHERE token_hash = ?');
        $stmt->execute([$hash]);
        $this->assertNotFalse($stmt->fetchColumn());
    }

    public function testIssueStoresLabel(): void
    {
        $this->dt->issue('user-1', label: 'My Phone');
        $list = $this->dt->list('user-1');
        $this->assertSame('My Phone', $list[0]['label']);
    }

    public function testIssueMultipleTokensForSameUser(): void
    {
        $this->dt->issue('user-1');
        $this->dt->issue('user-1');
        $this->assertCount(2, $this->dt->list('user-1'));
    }

    // ── validate ──────────────────────────────────────────────────────────────

    public function testValidateReturnsRowForValidToken(): void
    {
        $raw    = $this->dt->issue('user-1');
        $result = $this->dt->validate($raw);
        $this->assertNotNull($result);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('user-1', $result['user_id']);
    }

    public function testValidateReturnsNullForUnknownToken(): void
    {
        $this->assertNull($this->dt->validate(str_repeat('0', 64)));
    }

    public function testValidateReturnsNullForExpiredToken(): void
    {
        // Issue a token that's already expired
        $raw  = bin2hex(random_bytes(32));
        $hash = hash('sha256', $raw);
        $this->db->prepare(
            'INSERT INTO device_tokens (user_id, token_hash, expires_at)
             VALUES (\'user-1\', :hash, \'2000-01-01 00:00:00\')'
        )->execute([':hash' => $hash]);

        $this->assertNull($this->dt->validate($raw));
    }

    public function testValidateUpdatesLastUsedAt(): void
    {
        $raw = $this->dt->issue('user-1');
        $this->dt->validate($raw);
        $list = $this->dt->list('user-1');
        $this->assertNotNull($list[0]['last_used_at']);
    }

    // ── revoke ────────────────────────────────────────────────────────────────

    public function testRevokeRemovesToken(): void
    {
        $raw = $this->dt->issue('user-1');
        $this->assertTrue($this->dt->revoke($raw));
        $this->assertNull($this->dt->validate($raw));
    }

    public function testRevokeReturnsFalseForUnknownToken(): void
    {
        $this->assertFalse($this->dt->revoke(str_repeat('0', 64)));
    }

    // ── revokeAll ─────────────────────────────────────────────────────────────

    public function testRevokeAllRemovesAllUserTokens(): void
    {
        $this->dt->issue('user-1');
        $this->dt->issue('user-1');
        $this->assertSame(2, $this->dt->revokeAll('user-1'));
        $this->assertSame([], $this->dt->list('user-1'));
    }

    public function testRevokeAllDoesNotAffectOtherUsers(): void
    {
        $this->dt->issue('user-1');
        $this->dt->issue('user-2');
        $this->dt->revokeAll('user-1');
        $this->assertCount(1, $this->dt->list('user-2'));
    }

    // ── list ──────────────────────────────────────────────────────────────────

    public function testListReturnsEmptyForUnknownUser(): void
    {
        $this->assertSame([], $this->dt->list('nobody'));
    }

    public function testListExcludesExpiredTokens(): void
    {
        $this->dt->issue('user-1'); // valid
        // Insert expired token manually
        $this->db->exec(
            "INSERT INTO device_tokens (user_id, token_hash, expires_at)
             VALUES ('user-1', 'aaaa', '2000-01-01 00:00:00')"
        );
        $this->assertCount(1, $this->dt->list('user-1'));
    }

    public function testListDoesNotIncludeTokenHash(): void
    {
        $this->dt->issue('user-1');
        $list = $this->dt->list('user-1');
        $this->assertArrayNotHasKey('token_hash', $list[0]);
    }

    // ── purgeExpired ──────────────────────────────────────────────────────────

    public function testPurgeExpiredRemovesExpiredTokens(): void
    {
        $this->dt->issue('user-1'); // valid
        $this->db->exec(
            "INSERT INTO device_tokens (user_id, token_hash, expires_at)
             VALUES ('user-1', 'bbbb', '2000-01-01 00:00:00')"
        );
        $purged = $this->dt->purgeExpired('user-1');
        $this->assertSame(1, $purged);
        $this->assertCount(1, $this->dt->list('user-1'));
    }
}
