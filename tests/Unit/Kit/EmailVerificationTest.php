<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\EmailVerification;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for EmailVerification.
 */
final class EmailVerificationTest extends TestCase
{
    private PDO $db;
    private EmailVerification $ev;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE email_verifications (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id     VARCHAR(255) NOT NULL,
                email       VARCHAR(255) NOT NULL,
                token_hash  VARCHAR(64)  NOT NULL,
                verified_at DATETIME     DEFAULT NULL,
                expires_at  DATETIME     NOT NULL,
                created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (user_id, email)
            )
        ');
        $this->ev = new EmailVerification($this->db, ttlSeconds: 3600);
    }

    // ── issue ─────────────────────────────────────────────────────────────────

    public function testIssueReturns64HexToken(): void
    {
        $token = $this->ev->issue('user-1', 'a@example.com');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    public function testIssueStoresHashNotRawToken(): void
    {
        $token = $this->ev->issue('user-1', 'a@example.com');
        $stmt  = $this->db->query('SELECT token_hash FROM email_verifications LIMIT 1');
        $hash  = $stmt->fetchColumn();
        $this->assertNotSame($token, $hash);
        $this->assertSame(hash('sha256', $token), $hash);
    }

    public function testIssueSetsStatusPending(): void
    {
        $this->ev->issue('user-1', 'a@example.com');
        $this->assertTrue($this->ev->isPending('user-1', 'a@example.com'));
    }

    public function testIssueReplacesExistingToken(): void
    {
        $token1 = $this->ev->issue('user-1', 'a@example.com');
        $token2 = $this->ev->issue('user-1', 'a@example.com');
        $this->assertNotSame($token1, $token2);
        // Only one row should exist
        $stmt = $this->db->query('SELECT COUNT(*) FROM email_verifications');
        $this->assertSame(1, (int)$stmt->fetchColumn());
    }

    public function testIssueThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ev->issue('', 'a@example.com');
    }

    public function testIssueThrowsOnEmptyEmail(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ev->issue('user-1', '');
    }

    // ── verify ────────────────────────────────────────────────────────────────

    public function testVerifyReturnsTrueForValidToken(): void
    {
        $token = $this->ev->issue('user-1', 'a@example.com');
        $this->assertTrue($this->ev->verify($token));
    }

    public function testVerifySetsIsVerified(): void
    {
        $token = $this->ev->issue('user-1', 'a@example.com');
        $this->ev->verify($token);
        $this->assertTrue($this->ev->isVerified('user-1', 'a@example.com'));
    }

    public function testVerifyReturnsFalseForUnknownToken(): void
    {
        $this->assertFalse($this->ev->verify(str_repeat('0', 64)));
    }

    public function testVerifyReturnsFalseForAlreadyVerifiedToken(): void
    {
        $token = $this->ev->issue('user-1', 'a@example.com');
        $this->ev->verify($token);
        $this->assertFalse($this->ev->verify($token)); // second call
    }

    public function testVerifyReturnsFalseForExpiredToken(): void
    {
        // Insert an already-expired token directly
        $raw  = bin2hex(random_bytes(32));
        $hash = hash('sha256', $raw);
        $this->db->prepare(
            "INSERT INTO email_verifications (user_id, email, token_hash, expires_at)
             VALUES ('user-9', 'exp@example.com', :hash, '2000-01-01 00:00:00')"
        )->execute([':hash' => $hash]);
        $this->assertFalse($this->ev->verify($raw));
    }

    // ── isVerified / isPending ────────────────────────────────────────────────

    public function testIsVerifiedReturnsFalseBeforeVerification(): void
    {
        $this->ev->issue('user-1', 'a@example.com');
        $this->assertFalse($this->ev->isVerified('user-1', 'a@example.com'));
    }

    public function testIsVerifiedReturnsFalseForNonExistentEntry(): void
    {
        $this->assertFalse($this->ev->isVerified('user-1', 'nope@example.com'));
    }

    public function testIsPendingReturnsFalseAfterVerification(): void
    {
        $token = $this->ev->issue('user-1', 'a@example.com');
        $this->ev->verify($token);
        $this->assertFalse($this->ev->isPending('user-1', 'a@example.com'));
    }

    // ── expiresAt ─────────────────────────────────────────────────────────────

    public function testExpiresAtReturnsDateTimeImmutable(): void
    {
        $this->ev->issue('user-1', 'a@example.com');
        $exp = $this->ev->expiresAt('user-1', 'a@example.com');
        $this->assertInstanceOf(\DateTimeImmutable::class, $exp);
    }

    public function testExpiresAtReturnsNullForNonExistentEntry(): void
    {
        $this->assertNull($this->ev->expiresAt('user-1', 'nope@example.com'));
    }

    // ── revoke ────────────────────────────────────────────────────────────────

    public function testRevokeDeletesEntry(): void
    {
        $this->ev->issue('user-1', 'a@example.com');
        $this->assertTrue($this->ev->revoke('user-1', 'a@example.com'));
        $this->assertFalse($this->ev->isPending('user-1', 'a@example.com'));
    }

    public function testRevokeReturnsFalseIfNotPresent(): void
    {
        $this->assertFalse($this->ev->revoke('user-1', 'nope@example.com'));
    }

    // ── purgeExpired ──────────────────────────────────────────────────────────

    public function testPurgeExpiredRemovesExpiredTokens(): void
    {
        $this->db->exec(
            "INSERT INTO email_verifications (user_id, email, token_hash, expires_at)
             VALUES ('user-old', 'old@example.com', 'aabbcc', '2000-01-01 00:00:00')"
        );
        $this->ev->issue('user-1', 'a@example.com'); // valid
        $this->assertSame(1, $this->ev->purgeExpired());
    }
}
