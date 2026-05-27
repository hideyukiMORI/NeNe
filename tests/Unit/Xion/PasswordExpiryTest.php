<?php

declare(strict_types=1);

namespace Tests\Unit\Xion;

use Nene\Xion\PasswordExpiry;
use PDO;
use PHPUnit\Framework\TestCase;

final class PasswordExpiryTest extends TestCase
{
    private PDO $pdo;
    private PasswordExpiry $pe;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE password_expiry (
                user_id      VARCHAR(255) PRIMARY KEY,
                expires_at   DATETIME     NOT NULL,
                changed_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                must_change  TINYINT(1)   NOT NULL DEFAULT 0
            )
        ');
        $this->pe = new PasswordExpiry($this->pdo);
    }

    // ── set ───────────────────────────────────────────────────────────────────

    public function testSetCreatesRow(): void
    {
        $this->pe->set('user-1', 90);
        $row = $this->pe->get('user-1');
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('user-1', $row['user_id']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(0, (int)$row['must_change']);
    }

    public function testSetUpdatesExistingRow(): void
    {
        $this->pe->set('user-1', 90);
        $this->pe->set('user-1', 30); // shorter policy
        $row = $this->pe->get('user-1');
        $this->assertNotNull($row);
        // Expires within ~30 days (not 90)
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $expires = strtotime((string)$row['expires_at']);
        $this->assertGreaterThan(time() + (25 * 86400), $expires);
        $this->assertLessThan(time() + (35 * 86400), $expires);
    }

    public function testSetClearsMustChangeFlag(): void
    {
        $this->pe->set('user-1', 90);
        $this->pe->forceChange('user-1');
        $this->pe->set('user-1', 90); // password change clears flag
        $this->assertFalse($this->pe->mustChange('user-1'));
    }

    public function testSetThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->pe->set('', 90);
    }

    public function testSetThrowsOnZeroExpiryDays(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->pe->set('user-1', 0);
    }

    // ── get ───────────────────────────────────────────────────────────────────

    public function testGetReturnsNullForUnknownUser(): void
    {
        $this->assertNull($this->pe->get('unknown'));
    }

    // ── isExpired ─────────────────────────────────────────────────────────────

    public function testIsExpiredFalseForFutureExpiry(): void
    {
        $this->pe->set('user-1', 90);
        $this->assertFalse($this->pe->isExpired('user-1'));
    }

    public function testIsExpiredTrueForPastExpiry(): void
    {
        $this->pdo->exec(
            "INSERT INTO password_expiry (user_id, expires_at)
             VALUES ('user-1', '2020-01-01 00:00:00')"
        );
        $this->assertTrue($this->pe->isExpired('user-1'));
    }

    public function testIsExpiredFalseForUnknownUser(): void
    {
        $this->assertFalse($this->pe->isExpired('unknown'));
    }

    // ── mustChange ────────────────────────────────────────────────────────────

    public function testMustChangeFalseNormally(): void
    {
        $this->pe->set('user-1', 90);
        $this->assertFalse($this->pe->mustChange('user-1'));
    }

    public function testMustChangeTrueWhenExpired(): void
    {
        $this->pdo->exec(
            "INSERT INTO password_expiry (user_id, expires_at)
             VALUES ('user-1', '2020-01-01 00:00:00')"
        );
        $this->assertTrue($this->pe->mustChange('user-1'));
    }

    public function testMustChangeTrueWhenForceFlagSet(): void
    {
        $this->pe->set('user-1', 90);
        $this->pe->forceChange('user-1');
        $this->assertTrue($this->pe->mustChange('user-1'));
    }

    public function testMustChangeFalseForUnknownUser(): void
    {
        $this->assertFalse($this->pe->mustChange('unknown'));
    }

    // ── forceChange ───────────────────────────────────────────────────────────

    public function testForceChangeSetsFlag(): void
    {
        $this->pe->set('user-1', 90);
        $this->assertTrue($this->pe->forceChange('user-1'));
        $row = $this->pe->get('user-1');
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(1, (int)$row['must_change']);
    }

    public function testForceChangeReturnsFalseForUnknownUser(): void
    {
        $this->assertFalse($this->pe->forceChange('unknown'));
    }

    // ── clear ─────────────────────────────────────────────────────────────────

    public function testClearDeletesRow(): void
    {
        $this->pe->set('user-1', 90);
        $this->assertTrue($this->pe->clear('user-1'));
        $this->assertNull($this->pe->get('user-1'));
    }

    public function testClearReturnsFalseForUnknownUser(): void
    {
        $this->assertFalse($this->pe->clear('unknown'));
    }

    // ── expiringSoon ──────────────────────────────────────────────────────────

    public function testExpiringSoonReturnsUpcomingRows(): void
    {
        $now     = '2026-05-28 00:00:00';
        $expiry3 = '2026-05-31 00:00:00';
        $expiry6 = '2026-06-03 00:00:00';
        $this->pdo->exec(
            "INSERT INTO password_expiry (user_id, expires_at) VALUES ('user-1', '{$expiry3}')"
        );
        $this->pdo->exec(
            "INSERT INTO password_expiry (user_id, expires_at) VALUES ('user-2', '{$expiry6}')"
        );
        $rows = $this->pe->expiringSoon(7, $now);
        $this->assertCount(2, $rows);
    }

    public function testExpiringSoonExcludesAlreadyExpired(): void
    {
        $now    = '2026-05-28 00:00:00';
        $past   = '2026-05-20 00:00:00';
        $future = '2026-06-01 00:00:00';
        $this->pdo->exec(
            "INSERT INTO password_expiry (user_id, expires_at) VALUES ('user-1', '{$past}')"
        );
        $this->pdo->exec(
            "INSERT INTO password_expiry (user_id, expires_at) VALUES ('user-2', '{$future}')"
        );
        $rows = $this->pe->expiringSoon(7, $now);
        $this->assertCount(1, $rows);
        $this->assertSame('user-2', $rows[0]['user_id']);
    }

    public function testExpiringSoonReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->pe->expiringSoon(7, '2026-05-28 00:00:00'));
    }
}
