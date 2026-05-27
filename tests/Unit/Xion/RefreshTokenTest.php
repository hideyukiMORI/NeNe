<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\RefreshToken;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RefreshToken using an in-memory SQLite database.
 */
final class RefreshTokenTest extends TestCase
{
    private PDO $db;
    private RefreshToken $rt;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec(
            'CREATE TABLE refresh_tokens (
                id         INTEGER      PRIMARY KEY AUTOINCREMENT,
                user_id    VARCHAR(255) NOT NULL,
                token_hash VARCHAR(64)  NOT NULL UNIQUE,
                expires_at DATETIME     NOT NULL,
                revoked_at DATETIME     DEFAULT NULL,
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $this->rt = new RefreshToken($this->db);
    }

    // ── issue ─────────────────────────────────────────────────────────────────

    public function testIssueReturns64CharHexToken(): void
    {
        $raw = $this->rt->issue('user:1');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $raw);
    }

    public function testIssueStoresHashNotRaw(): void
    {
        $raw = $this->rt->issue('user:1');
        $row = $this->db->query('SELECT token_hash FROM refresh_tokens LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        // Hash stored, not raw value
        $this->assertNotSame($raw, $row['token_hash']);
        $this->assertSame(hash('sha256', $raw), $row['token_hash']);
    }

    public function testIssueWithIntUserId(): void
    {
        $raw = $this->rt->issue(42);
        $row = $this->db->query('SELECT user_id FROM refresh_tokens LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        $this->assertSame('42', $row['user_id']);
    }

    public function testIssueTwoTokensAreDistinct(): void
    {
        $a = $this->rt->issue('user:1');
        $b = $this->rt->issue('user:1');
        $this->assertNotSame($a, $b);
    }

    // ── rotate ────────────────────────────────────────────────────────────────

    public function testRotateValidTokenReturnsNewTokenAndUserId(): void
    {
        $old    = $this->rt->issue('user:1');
        $result = $this->rt->rotate($old);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('rawToken', $result);
        $this->assertArrayHasKey('userId', $result);
        $this->assertSame('user:1', $result['userId']);
        $this->assertNotSame($old, $result['rawToken']);
    }

    public function testRotateRevokesOldToken(): void
    {
        $old = $this->rt->issue('user:1');
        $this->rt->rotate($old);
        // Old token should no longer rotate
        $this->assertNull($this->rt->rotate($old));
    }

    public function testRotateUnknownTokenReturnsNull(): void
    {
        $this->assertNull($this->rt->rotate('deadbeef' . str_repeat('0', 56)));
    }

    public function testRotateExpiredTokenReturnsNull(): void
    {
        $raw = $this->rt->issue('user:1');
        // Force expires_at into the past
        $this->db->exec("UPDATE refresh_tokens SET expires_at = '2000-01-01 00:00:00'");
        $this->assertNull($this->rt->rotate($raw));
    }

    public function testRotateRevokedTokenRevokesAllAndReturnsNull(): void
    {
        // Issue two tokens for the same user
        $raw1 = $this->rt->issue('user:1');
        $raw2 = $this->rt->issue('user:1');

        // Rotate raw1 — raw1 is now revoked, raw2 is still active
        $result = $this->rt->rotate($raw1);
        $this->assertNotNull($result);

        // Present already-revoked raw1 again — replay attack
        $replay = $this->rt->rotate($raw1);
        $this->assertNull($replay);

        // raw2 should now be revoked too (revokeAll triggered)
        $this->assertNull($this->rt->rotate($raw2));
    }

    public function testRotatedNewTokenIsUsable(): void
    {
        $old    = $this->rt->issue('user:1');
        $result = $this->rt->rotate($old);
        $this->assertIsArray($result);

        $result2 = $this->rt->rotate($result['rawToken']);
        $this->assertIsArray($result2);
        $this->assertSame('user:1', $result2['userId']);
    }

    // ── revoke ────────────────────────────────────────────────────────────────

    public function testRevokeActiveToken(): void
    {
        $raw = $this->rt->issue('user:1');
        $this->rt->revoke($raw);
        // After revocation, rotate returns null
        $this->assertNull($this->rt->rotate($raw));
    }

    public function testRevokeUnknownTokenIsNoop(): void
    {
        // Should not throw
        $this->rt->revoke('unknowntoken' . str_repeat('x', 53));
        $this->assertTrue(true);
    }

    public function testRevokeAlreadyRevokedIsNoop(): void
    {
        $raw = $this->rt->issue('user:1');
        $this->rt->revoke($raw);
        $this->rt->revoke($raw); // Second call — no exception
        $this->assertTrue(true);
    }

    // ── revokeAll ─────────────────────────────────────────────────────────────

    public function testRevokeAllInvalidatesAllTokensForUser(): void
    {
        $raw1 = $this->rt->issue('user:1');
        $raw2 = $this->rt->issue('user:1');
        $this->rt->revokeAll('user:1');
        $this->assertNull($this->rt->rotate($raw1));
        $this->assertNull($this->rt->rotate($raw2));
    }

    public function testRevokeAllDoesNotAffectOtherUsers(): void
    {
        $this->rt->issue('user:1');
        $raw2 = $this->rt->issue('user:2');
        $this->rt->revokeAll('user:1');
        // user:2's token should still work
        $result = $this->rt->rotate($raw2);
        $this->assertNotNull($result);
        $this->assertSame('user:2', $result['userId']);
    }

    // ── hash ──────────────────────────────────────────────────────────────────

    public function testHashReturnsSha256(): void
    {
        $raw  = 'test-token';
        $hash = $this->rt->hash($raw);
        $this->assertSame(hash('sha256', $raw), $hash);
        $this->assertSame(64, strlen($hash));
    }
}
