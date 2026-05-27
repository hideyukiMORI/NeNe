<?php

declare(strict_types=1);

namespace Tests\Unit\Xion;

use Nene\Xion\OtpChallenge;
use PDO;
use PHPUnit\Framework\TestCase;

final class OtpChallengeTest extends TestCase
{
    private PDO $pdo;
    private OtpChallenge $otp;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE otp_challenges (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id    VARCHAR(255) NOT NULL,
                purpose    VARCHAR(100) NOT NULL,
                code_hash  VARCHAR(64)  NOT NULL,
                expires_at DATETIME     NOT NULL,
                attempts   INTEGER      NOT NULL DEFAULT 0,
                used       INTEGER      NOT NULL DEFAULT 0,
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->otp = new OtpChallenge($this->pdo);
    }

    // ── issue ─────────────────────────────────────────────────────────────────

    public function testIssueReturnsIdAndCode(): void
    {
        [$id, $code] = $this->otp->issue('user-1', 'email-verify');
        $this->assertGreaterThan(0, $id);
        $this->assertSame(6, strlen($code)); // default length
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
    }

    public function testIssueStoresHashedCode(): void
    {
        [$id, $code] = $this->otp->issue('user-1', 'email-verify');
        $row = $this->otp->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(hash('sha256', $code), $row['code_hash']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(0, (int)$row['used']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(0, (int)$row['attempts']);
    }

    public function testIssueCustomLength(): void
    {
        [, $code] = $this->otp->issue('user-1', 'phone-verify', 8);
        $this->assertSame(8, strlen($code));
    }

    public function testIssueInvalidatesPreviousChallenge(): void
    {
        [$id1] = $this->otp->issue('user-1', 'email-verify', 6, 900);
        $this->otp->issue('user-1', 'email-verify', 6, 900);
        $row = $this->otp->get($id1);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(1, (int)$row['used']); // invalidated
    }

    public function testIssueDifferentPurposesCoexist(): void
    {
        [$id1] = $this->otp->issue('user-1', 'email-verify', 6, 900);
        $this->otp->issue('user-1', 'phone-verify', 6, 900);
        $row = $this->otp->get($id1);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(0, (int)$row['used']); // not invalidated
    }

    public function testIssueThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->otp->issue('', 'email-verify');
    }

    public function testIssueThrowsOnEmptyPurpose(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->otp->issue('user-1', '');
    }

    public function testIssueThrowsOnTooShortLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->otp->issue('user-1', 'email-verify', 3);
    }

    // ── get ───────────────────────────────────────────────────────────────────

    public function testGetReturnsNullForMissingId(): void
    {
        $this->assertNull($this->otp->get(9999));
    }

    // ── verify ────────────────────────────────────────────────────────────────

    public function testVerifyReturnsTrueForCorrectCode(): void
    {
        [$id, $code] = $this->otp->issue('user-1', 'email-verify');
        $this->assertTrue($this->otp->verify($id, $code));
    }

    public function testVerifyReturnsFalseForWrongCode(): void
    {
        [$id] = $this->otp->issue('user-1', 'email-verify');
        $this->assertFalse($this->otp->verify($id, '000000'));
    }

    public function testVerifyIncreasesAttemptCount(): void
    {
        [$id] = $this->otp->issue('user-1', 'email-verify');
        $this->otp->verify($id, '000000'); // wrong
        $row = $this->otp->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(1, (int)$row['attempts']);
    }

    public function testVerifyMarksUsedOnSuccess(): void
    {
        [$id, $code] = $this->otp->issue('user-1', 'email-verify');
        $this->otp->verify($id, $code);
        $row = $this->otp->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(1, (int)$row['used']);
    }

    public function testVerifyReturnsFalseAfterUsed(): void
    {
        [$id, $code] = $this->otp->issue('user-1', 'email-verify');
        $this->otp->verify($id, $code);
        $this->assertFalse($this->otp->verify($id, $code)); // already used
    }

    public function testVerifyReturnsFalseWhenExpired(): void
    {
        [$id] = $this->otp->issue('user-1', 'email-verify', 6, 1);
        // Manually expire it
        $this->pdo->exec("UPDATE otp_challenges SET expires_at = '2000-01-01 00:00:00' WHERE id = {$id}");
        $this->assertFalse($this->otp->verify($id, '123456'));
    }

    public function testVerifyReturnsFalseAfterMaxAttempts(): void
    {
        [$id] = $this->otp->issue('user-1', 'email-verify');
        for ($i = 0; $i < 3; $i++) {
            $this->otp->verify($id, '000000', 3);
        }
        $this->assertFalse($this->otp->verify($id, '000000', 3));
    }

    public function testVerifyReturnsFalseForMissingChallenge(): void
    {
        $this->assertFalse($this->otp->verify(9999, '123456'));
    }

    // ── isValid ───────────────────────────────────────────────────────────────

    public function testIsValidTrueForFreshChallenge(): void
    {
        [$id] = $this->otp->issue('user-1', 'email-verify');
        $this->assertTrue($this->otp->isValid($id));
    }

    public function testIsValidFalseAfterUse(): void
    {
        [$id, $code] = $this->otp->issue('user-1', 'email-verify');
        $this->otp->verify($id, $code);
        $this->assertFalse($this->otp->isValid($id));
    }

    public function testIsValidFalseForMissingId(): void
    {
        $this->assertFalse($this->otp->isValid(9999));
    }

    // ── invalidate ────────────────────────────────────────────────────────────

    public function testInvalidateMarksUsed(): void
    {
        [$id] = $this->otp->issue('user-1', 'email-verify');
        $this->assertTrue($this->otp->invalidate($id));
        $this->assertFalse($this->otp->isValid($id));
    }

    public function testInvalidateReturnsFalseWhenAlreadyUsed(): void
    {
        [$id, $code] = $this->otp->issue('user-1', 'email-verify');
        $this->otp->verify($id, $code);
        $this->assertFalse($this->otp->invalidate($id));
    }

    // ── purgeOlderThan ────────────────────────────────────────────────────────

    public function testPurgeOlderThanDeletesOldUsedChallenges(): void
    {
        [$id] = $this->otp->issue('user-1', 'email-verify');
        $this->pdo->exec(
            "UPDATE otp_challenges SET used = 1, created_at = '2020-01-01 00:00:00' WHERE id = {$id}"
        );
        $deleted = $this->otp->purgeOlderThan('2021-01-01 00:00:00');
        $this->assertSame(1, $deleted);
    }
}
