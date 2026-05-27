<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\OAuthToken;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for OAuthToken.
 */
final class OAuthTokenTest extends TestCase
{
    private PDO $db;
    private OAuthToken $ot;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE oauth_tokens (
                id                 INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id            VARCHAR(255) NOT NULL,
                client_id          VARCHAR(255) NOT NULL DEFAULT \'\',
                scope              TEXT         NOT NULL DEFAULT \'\',
                access_hash        VARCHAR(64)  NOT NULL UNIQUE,
                refresh_hash       VARCHAR(64)  NOT NULL UNIQUE,
                access_expires_at  DATETIME     NOT NULL,
                refresh_expires_at DATETIME     NOT NULL,
                revoked            TINYINT(1)   NOT NULL DEFAULT 0,
                issued_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->ot = new OAuthToken($this->db);
    }

    // ── issue ─────────────────────────────────────────────────────────────────

    public function testIssueReturnsTwoTokens(): void
    {
        [$access, $refresh] = $this->ot->issue('user-1');
        $this->assertNotEmpty($access);
        $this->assertNotEmpty($refresh);
        $this->assertNotSame($access, $refresh);
    }

    public function testIssueTokensAre64HexChars(): void
    {
        [$access, $refresh] = $this->ot->issue('user-1');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $access);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $refresh);
    }

    public function testIssueThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ot->issue('');
    }

    public function testIssueMultipleTokensAreUnique(): void
    {
        [$a1] = $this->ot->issue('user-1');
        [$a2] = $this->ot->issue('user-1');
        $this->assertNotSame($a1, $a2);
    }

    // ── verifyAccess ──────────────────────────────────────────────────────────

    public function testVerifyAccessReturnsRecordForValidToken(): void
    {
        [$access] = $this->ot->issue('user-1', 'my-app', ['read']);
        $record   = $this->ot->verifyAccess($access);
        $this->assertNotNull($record);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('user-1', $record['user_id']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('my-app', $record['client_id']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('read', $record['scope']);
    }

    public function testVerifyAccessReturnsNullForUnknownToken(): void
    {
        $this->assertNull($this->ot->verifyAccess('deadbeef'));
    }

    public function testVerifyAccessReturnsNullForExpiredToken(): void
    {
        $past = (new \DateTimeImmutable())->modify('-1 second')->format('Y-m-d H:i:s');
        $far  = (new \DateTimeImmutable())->modify('+30 days')->format('Y-m-d H:i:s');
        $raw  = 'expiredaccesstoken0000000000000000000000000000000000000000000000';
        $this->db->exec("INSERT INTO oauth_tokens
            (user_id, access_hash, refresh_hash, access_expires_at, refresh_expires_at)
            VALUES ('user-1', '" . hash('sha256', $raw) . "', 'aabbcc', '{$past}', '{$far}')");
        $this->assertNull($this->ot->verifyAccess($raw));
    }

    public function testVerifyAccessReturnsNullForRevokedToken(): void
    {
        [$access] = $this->ot->issue('user-1');
        $this->ot->revokeAccess($access);
        $this->assertNull($this->ot->verifyAccess($access));
    }

    // ── refresh ───────────────────────────────────────────────────────────────

    public function testRefreshIssuesNewTokenPair(): void
    {
        [, $refresh] = $this->ot->issue('user-1', 'app', ['read', 'write']);
        $result      = $this->ot->refresh($refresh);
        $this->assertNotNull($result);
        $this->assertIsArray($result);
        $this->assertCount(2, $result);
    }

    public function testRefreshRevokesOldTokens(): void
    {
        [$access, $refresh] = $this->ot->issue('user-1');
        $this->ot->refresh($refresh);
        // Old access token should now be invalid
        $this->assertNull($this->ot->verifyAccess($access));
    }

    public function testRefreshReturnsNullForInvalidRefreshToken(): void
    {
        $this->assertNull($this->ot->refresh('invalid'));
    }

    public function testRefreshCannotBeUsedTwice(): void
    {
        [, $refresh] = $this->ot->issue('user-1');
        $this->ot->refresh($refresh);
        // Second use of the same refresh token should fail
        $this->assertNull($this->ot->refresh($refresh));
    }

    // ── revokeAccess ──────────────────────────────────────────────────────────

    public function testRevokeAccessReturnsTrueAndInvalidates(): void
    {
        [$access] = $this->ot->issue('user-1');
        $this->assertTrue($this->ot->revokeAccess($access));
        $this->assertNull($this->ot->verifyAccess($access));
    }

    public function testRevokeAccessReturnsFalseForUnknown(): void
    {
        $this->assertFalse($this->ot->revokeAccess('unknown'));
    }

    // ── revokeAllForUser ──────────────────────────────────────────────────────

    public function testRevokeAllForUserRevokesAllPairs(): void
    {
        [$a1] = $this->ot->issue('user-1');
        [$a2] = $this->ot->issue('user-1');
        $this->ot->issue('user-2'); // different user

        $count = $this->ot->revokeAllForUser('user-1');
        $this->assertSame(2, $count);
        $this->assertNull($this->ot->verifyAccess($a1));
        $this->assertNull($this->ot->verifyAccess($a2));
    }

    // ── activeCount ───────────────────────────────────────────────────────────

    public function testActiveCountReturnsCorrectNumber(): void
    {
        $this->ot->issue('user-1');
        $this->ot->issue('user-1');
        $this->assertSame(2, $this->ot->activeCount('user-1'));
        $this->assertSame(0, $this->ot->activeCount('user-2'));
    }

    // ── purgeExpired ──────────────────────────────────────────────────────────

    public function testPurgeExpiredDeletesRevokedTokens(): void
    {
        [$access] = $this->ot->issue('user-1');
        $this->ot->revokeAccess($access);
        $deleted = $this->ot->purgeExpired();
        $this->assertGreaterThan(0, $deleted);
    }

    public function testPurgeExpiredKeepsActiveTokens(): void
    {
        $this->ot->issue('user-1');
        $this->assertSame(0, $this->ot->purgeExpired());
        $this->assertSame(1, $this->ot->activeCount('user-1'));
    }
}
