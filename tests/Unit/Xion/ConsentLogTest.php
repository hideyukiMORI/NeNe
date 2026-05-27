<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\ConsentLog;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ConsentLog.
 */
final class ConsentLogTest extends TestCase
{
    private PDO $db;
    private ConsentLog $cl;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE consent_log (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id    VARCHAR(255) NOT NULL,
                purpose    VARCHAR(100) NOT NULL,
                action     VARCHAR(10)  NOT NULL,
                policy_ver VARCHAR(20)  NOT NULL DEFAULT \'\',
                ip_address VARCHAR(45)  NOT NULL DEFAULT \'\',
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->cl = new ConsentLog($this->db);
    }

    // ── grant ─────────────────────────────────────────────────────────────────

    public function testGrantReturnsId(): void
    {
        $id = $this->cl->grant('user-1', 'marketing_email');
        $this->assertGreaterThan(0, $id);
    }

    public function testGrantSetsHasConsentedTrue(): void
    {
        $this->cl->grant('user-1', 'marketing_email');
        $this->assertTrue($this->cl->hasConsented('user-1', 'marketing_email'));
    }

    public function testGrantStoresPolicyVersion(): void
    {
        $this->cl->grant('user-1', 'analytics', '2.0', '1.2.3.4');
        $row = $this->cl->current('user-1', 'analytics');
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('2.0', $row['policy_ver']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('1.2.3.4', $row['ip_address']);
    }

    // ── withdraw ──────────────────────────────────────────────────────────────

    public function testWithdrawSetsHasConsentedFalse(): void
    {
        $this->cl->grant('user-1', 'marketing_email');
        $this->cl->withdraw('user-1', 'marketing_email');
        $this->assertFalse($this->cl->hasConsented('user-1', 'marketing_email'));
    }

    public function testWithdrawAfterWithdrawStaysWithdrawn(): void
    {
        $this->cl->grant('user-1', 'marketing_email');
        $this->cl->withdraw('user-1', 'marketing_email');
        $this->cl->withdraw('user-1', 'marketing_email');
        $this->assertFalse($this->cl->hasConsented('user-1', 'marketing_email'));
    }

    public function testGrantAfterWithdrawResetsConsent(): void
    {
        $this->cl->grant('user-1', 'marketing_email');
        $this->cl->withdraw('user-1', 'marketing_email');
        $this->cl->grant('user-1', 'marketing_email');
        $this->assertTrue($this->cl->hasConsented('user-1', 'marketing_email'));
    }

    // ── hasConsented ──────────────────────────────────────────────────────────

    public function testHasConsentedReturnsFalseForUnknownUser(): void
    {
        $this->assertFalse($this->cl->hasConsented('nobody', 'marketing_email'));
    }

    public function testConsentIsScopedToPurpose(): void
    {
        $this->cl->grant('user-1', 'analytics');
        $this->assertFalse($this->cl->hasConsented('user-1', 'marketing_email'));
    }

    public function testConsentIsScopedToUser(): void
    {
        $this->cl->grant('user-1', 'analytics');
        $this->assertFalse($this->cl->hasConsented('user-2', 'analytics'));
    }

    // ── current ───────────────────────────────────────────────────────────────

    public function testCurrentReturnsLatestEntry(): void
    {
        $this->cl->grant('user-1', 'analytics');
        $this->cl->withdraw('user-1', 'analytics');
        $row = $this->cl->current('user-1', 'analytics');
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('withdraw', $row['action']);
    }

    public function testCurrentReturnsNullForUnknown(): void
    {
        $this->assertNull($this->cl->current('nobody', 'analytics'));
    }

    // ── history ───────────────────────────────────────────────────────────────

    public function testHistoryReturnsAllEntries(): void
    {
        $this->cl->grant('user-1', 'analytics');
        $this->cl->withdraw('user-1', 'analytics');
        $this->cl->grant('user-1', 'analytics');
        $rows = $this->cl->history('user-1', 'analytics');
        $this->assertCount(3, $rows);
    }

    public function testHistoryIsInAscendingOrder(): void
    {
        $this->cl->grant('user-1', 'analytics');
        $this->cl->withdraw('user-1', 'analytics');
        $rows = $this->cl->history('user-1', 'analytics');
        $this->assertSame('grant', $rows[0]['action']);
        $this->assertSame('withdraw', $rows[1]['action']);
    }

    public function testHistoryReturnsEmptyForUnknown(): void
    {
        $this->assertSame([], $this->cl->history('nobody', 'analytics'));
    }

    // ── activeConsents ────────────────────────────────────────────────────────

    public function testActiveConsentsReturnsGrantedPurposes(): void
    {
        $this->cl->grant('user-1', 'analytics');
        $this->cl->grant('user-1', 'marketing_email');
        $this->cl->withdraw('user-1', 'analytics');
        $active = $this->cl->activeConsents('user-1');
        $this->assertCount(1, $active);
        $this->assertContains('marketing_email', $active);
    }

    public function testActiveConsentsReturnsEmptyForNoConsents(): void
    {
        $this->assertSame([], $this->cl->activeConsents('nobody'));
    }

    // ── consentedCount ────────────────────────────────────────────────────────

    public function testConsentedCountReturnsNumberOfUsersWithActiveConsent(): void
    {
        $this->cl->grant('user-1', 'analytics');
        $this->cl->grant('user-2', 'analytics');
        $this->cl->grant('user-3', 'analytics');
        $this->cl->withdraw('user-3', 'analytics');
        $this->assertSame(2, $this->cl->consentedCount('analytics'));
    }

    public function testConsentedCountReturnsZeroForNoneConsented(): void
    {
        $this->assertSame(0, $this->cl->consentedCount('analytics'));
    }

    // ── eraseUser ─────────────────────────────────────────────────────────────

    public function testEraseUserDeletesAllRecords(): void
    {
        $this->cl->grant('user-1', 'analytics');
        $this->cl->grant('user-1', 'marketing_email');
        $deleted = $this->cl->eraseUser('user-1');
        $this->assertSame(2, $deleted);
        $this->assertFalse($this->cl->hasConsented('user-1', 'analytics'));
    }

    public function testEraseUserDoesNotAffectOtherUsers(): void
    {
        $this->cl->grant('user-1', 'analytics');
        $this->cl->grant('user-2', 'analytics');
        $this->cl->eraseUser('user-1');
        $this->assertTrue($this->cl->hasConsented('user-2', 'analytics'));
    }

    // ── validation ────────────────────────────────────────────────────────────

    public function testGrantThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cl->grant('', 'analytics');
    }

    public function testGrantThrowsOnEmptyPurpose(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cl->grant('user-1', '');
    }

    public function testWithdrawThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cl->withdraw('', 'analytics');
    }
}
