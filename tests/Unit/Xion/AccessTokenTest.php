<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\AccessToken;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AccessToken.
 */
final class AccessTokenTest extends TestCase
{
    private PDO $db;
    private AccessToken $at;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE access_tokens (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id    VARCHAR(255) NOT NULL,
                name       VARCHAR(255) NOT NULL DEFAULT \'\',
                token_hash VARCHAR(64)  NOT NULL UNIQUE,
                scopes     TEXT         NOT NULL DEFAULT \'[]\',
                last_used  DATETIME     DEFAULT NULL,
                expires_at DATETIME     DEFAULT NULL,
                revoked_at DATETIME     DEFAULT NULL,
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->at = new AccessToken($this->db);
    }

    // ── issue ─────────────────────────────────────────────────────────────────

    public function testIssueReturns64CharHexToken(): void
    {
        $token = $this->at->issue('user-1');
        $this->assertSame(64, strlen($token));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    public function testIssueTokensAreUnique(): void
    {
        $t1 = $this->at->issue('user-1');
        $t2 = $this->at->issue('user-1');
        $this->assertNotSame($t1, $t2);
    }

    public function testIssueThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->at->issue('');
    }

    public function testIssueStoresScopes(): void
    {
        $token = $this->at->issue('user-1', 'CI', ['repo:read', 'deploy']);
        $info  = $this->at->verify($token);
        $this->assertNotNull($info);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(['repo:read', 'deploy'], $info['scopes']);
    }

    // ── verify ────────────────────────────────────────────────────────────────

    public function testVerifyReturnsInfoForValidToken(): void
    {
        $token = $this->at->issue('user-1', 'My token');
        $info  = $this->at->verify($token);
        $this->assertNotNull($info);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('user-1', $info['user_id']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('My token', $info['name']);
    }

    public function testVerifyReturnsNullForInvalidToken(): void
    {
        $this->assertNull($this->at->verify('deadbeef' . str_repeat('0', 56)));
    }

    public function testVerifyReturnsNullForRevokedToken(): void
    {
        $token = $this->at->issue('user-1');
        $this->at->revoke($token);
        $this->assertNull($this->at->verify($token));
    }

    public function testVerifyReturnsNullForExpiredToken(): void
    {
        $past  = new \DateTimeImmutable('-1 second');
        $token = $this->at->issue('user-1', '', [], $past);
        $this->assertNull($this->at->verify($token));
    }

    public function testVerifyUpdatesLastUsed(): void
    {
        $token = $this->at->issue('user-1');
        $this->at->verify($token);
        $list = $this->at->listForUser('user-1');
        $this->assertNotNull($list[0]['last_used']);
    }

    // ── isValid ───────────────────────────────────────────────────────────────

    public function testIsValidTrueForActiveToken(): void
    {
        $token = $this->at->issue('user-1');
        $this->assertTrue($this->at->isValid($token));
    }

    public function testIsValidFalseAfterRevoke(): void
    {
        $token = $this->at->issue('user-1');
        $this->at->revoke($token);
        $this->assertFalse($this->at->isValid($token));
    }

    // ── revoke ────────────────────────────────────────────────────────────────

    public function testRevokeReturnsTrueWhenRevoked(): void
    {
        $token = $this->at->issue('user-1');
        $this->assertTrue($this->at->revoke($token));
    }

    public function testRevokeReturnsFalseIfAlreadyRevoked(): void
    {
        $token = $this->at->issue('user-1');
        $this->at->revoke($token);
        $this->assertFalse($this->at->revoke($token));
    }

    public function testRevokeReturnsFalseForUnknownToken(): void
    {
        $this->assertFalse($this->at->revoke(str_repeat('0', 64)));
    }

    // ── revokeAll ─────────────────────────────────────────────────────────────

    public function testRevokeAllRevokesAllUserTokens(): void
    {
        $this->at->issue('user-1');
        $this->at->issue('user-1');
        $this->assertSame(2, $this->at->revokeAll('user-1'));
        $this->assertSame([], $this->at->listForUser('user-1'));
    }

    public function testRevokeAllDoesNotAffectOtherUsers(): void
    {
        $this->at->issue('user-1');
        $this->at->issue('user-2');
        $this->at->revokeAll('user-1');
        $this->assertCount(1, $this->at->listForUser('user-2'));
    }

    public function testRevokeAllThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->at->revokeAll('');
    }

    // ── listForUser ───────────────────────────────────────────────────────────

    public function testListForUserReturnsActiveTokens(): void
    {
        $this->at->issue('user-1', 'Token A');
        $this->at->issue('user-1', 'Token B');
        $this->assertCount(2, $this->at->listForUser('user-1'));
    }

    public function testListForUserExcludesRevokedByDefault(): void
    {
        $token = $this->at->issue('user-1', 'Token A');
        $this->at->issue('user-1', 'Token B');
        $this->at->revoke($token);
        $this->assertCount(1, $this->at->listForUser('user-1'));
    }

    public function testListForUserIncludesRevokedWhenRequested(): void
    {
        $token = $this->at->issue('user-1', 'Token A');
        $this->at->revoke($token);
        $this->assertCount(1, $this->at->listForUser('user-1', includeRevoked: true));
    }

    public function testListForUserThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->at->listForUser('');
    }

    // ── purgeOlderThan ────────────────────────────────────────────────────────

    public function testPurgeOlderThanDeletesRevokedOldTokens(): void
    {
        $token = $this->at->issue('user-1');
        $this->at->revoke($token);
        $this->db->exec("UPDATE access_tokens SET created_at = datetime('now', '-8 days')");
        $this->assertSame(1, $this->at->purgeOlderThan(7));
    }

    public function testPurgeOlderThanPreservesActiveTokens(): void
    {
        $this->at->issue('user-1');
        $this->db->exec("UPDATE access_tokens SET created_at = datetime('now', '-8 days')");
        $this->assertSame(0, $this->at->purgeOlderThan(7)); // active token, not purged
    }
}
