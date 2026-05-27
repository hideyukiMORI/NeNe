<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\UserSession;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for UserSession.
 */
final class UserSessionTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE user_sessions (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id     VARCHAR(255) NOT NULL,
                token_hash  VARCHAR(64)  NOT NULL UNIQUE,
                payload     TEXT         NOT NULL DEFAULT \'{}\',
                expires_at  DATETIME     NOT NULL,
                last_active DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
    }

    private function us(int $ttl = 3600): UserSession
    {
        return new UserSession($this->db, $ttl);
    }

    // ── constructor ───────────────────────────────────────────────────────────

    public function testConstructorThrowsOnNonPositiveTtl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        // @phan-suppress-next-line PhanNoopNew
        new UserSession($this->db, 0);
    }

    // ── create ────────────────────────────────────────────────────────────────

    public function testCreateReturns64CharHexToken(): void
    {
        $token = $this->us()->create('user-1');
        $this->assertSame(64, strlen($token));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    public function testCreateTokensAreUnique(): void
    {
        $us = $this->us();
        $t1 = $us->create('user-1');
        $t2 = $us->create('user-1');
        $this->assertNotSame($t1, $t2);
    }

    public function testCreateThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->us()->create('');
    }

    public function testCreateStoresPayload(): void
    {
        $us    = $this->us();
        $token = $us->create('user-1', ['ip' => '1.2.3.4']);
        $info  = $us->validate($token);
        $this->assertNotNull($info);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(['ip' => '1.2.3.4'], $info['payload']);
    }

    // ── validate ──────────────────────────────────────────────────────────────

    public function testValidateReturnsInfoForActiveSession(): void
    {
        $us    = $this->us();
        $token = $us->create('user-1');
        $info  = $us->validate($token);
        $this->assertNotNull($info);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('user-1', $info['user_id']);
    }

    public function testValidateReturnsNullForInvalidToken(): void
    {
        $this->assertNull($this->us()->validate(str_repeat('0', 64)));
    }

    public function testValidateReturnsNullForExpiredSession(): void
    {
        $us    = $this->us(ttl: 3600);
        $token = $us->create('user-1');
        // Age the session past expiry
        $this->db->exec("UPDATE user_sessions SET expires_at = datetime('now', '-1 second')");
        $this->assertNull($us->validate($token));
    }

    public function testValidateRefreshesTtl(): void
    {
        $us    = $this->us(ttl: 3600);
        $token = $us->create('user-1');
        // Get original expiry
        $before = $this->db->query('SELECT expires_at FROM user_sessions LIMIT 1')->fetchColumn();
        sleep(1);
        $us->validate($token);
        $after = $this->db->query('SELECT expires_at FROM user_sessions LIMIT 1')->fetchColumn();
        $this->assertGreaterThanOrEqual($before, $after);
    }

    // ── isValid ───────────────────────────────────────────────────────────────

    public function testIsValidTrueForActiveSession(): void
    {
        $us    = $this->us();
        $token = $us->create('user-1');
        $this->assertTrue($us->isValid($token));
    }

    public function testIsValidFalseForExpiredSession(): void
    {
        $us    = $this->us();
        $token = $us->create('user-1');
        $this->db->exec("UPDATE user_sessions SET expires_at = datetime('now', '-1 second')");
        $this->assertFalse($us->isValid($token));
    }

    // ── revoke ────────────────────────────────────────────────────────────────

    public function testRevokeDeletesSession(): void
    {
        $us    = $this->us();
        $token = $us->create('user-1');
        $this->assertTrue($us->revoke($token));
        $this->assertFalse($us->isValid($token));
    }

    public function testRevokeReturnsFalseForUnknownToken(): void
    {
        $this->assertFalse($this->us()->revoke(str_repeat('0', 64)));
    }

    // ── revokeAll ─────────────────────────────────────────────────────────────

    public function testRevokeAllDeletesAllUserSessions(): void
    {
        $us = $this->us();
        $us->create('user-1');
        $us->create('user-1');
        $this->assertSame(2, $us->revokeAll('user-1'));
        $this->assertSame(0, $us->countForUser('user-1'));
    }

    public function testRevokeAllDoesNotAffectOtherUsers(): void
    {
        $us = $this->us();
        $us->create('user-1');
        $us->create('user-2');
        $us->revokeAll('user-1');
        $this->assertSame(1, $us->countForUser('user-2'));
    }

    // ── listForUser ───────────────────────────────────────────────────────────

    public function testListForUserReturnsActiveSessions(): void
    {
        $us = $this->us();
        $us->create('user-1');
        $us->create('user-1');
        $this->assertCount(2, $us->listForUser('user-1'));
    }

    public function testListForUserExcludesExpiredSessions(): void
    {
        $us = $this->us();
        $us->create('user-1');
        $this->db->exec("UPDATE user_sessions SET expires_at = datetime('now', '-1 second')");
        $this->assertCount(0, $us->listForUser('user-1'));
    }

    // ── purgeExpired ──────────────────────────────────────────────────────────

    public function testPurgeExpiredDeletesExpiredSessions(): void
    {
        $us = $this->us();
        $us->create('user-1');
        $this->db->exec("UPDATE user_sessions SET expires_at = datetime('now', '-1 second')");
        $this->assertSame(1, $us->purgeExpired());
    }

    public function testPurgeExpiredPreservesActiveSessions(): void
    {
        $us = $this->us();
        $us->create('user-1');
        $this->assertSame(0, $us->purgeExpired());
    }
}
