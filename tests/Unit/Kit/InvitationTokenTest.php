<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\InvitationToken;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for InvitationToken using an in-memory SQLite database.
 */
final class InvitationTokenTest extends TestCase
{
    private PDO $db;
    private InvitationToken $inv;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec(
            'CREATE TABLE invitations (
                id           INTEGER      PRIMARY KEY AUTOINCREMENT,
                inviter_id   VARCHAR(255) NOT NULL,
                email        VARCHAR(255) NOT NULL,
                token        VARCHAR(64)  NOT NULL UNIQUE,
                status       VARCHAR(16)  NOT NULL DEFAULT \'pending\',
                expires_at   DATETIME     NOT NULL,
                accepted_at  DATETIME     DEFAULT NULL,
                cancelled_at DATETIME     DEFAULT NULL,
                created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $this->inv = new InvitationToken($this->db);
    }

    // ── create ───────────────────────────────────────────────────────────────

    public function testCreateReturnsTokenAndId(): void
    {
        $result = $this->inv->create('user:1', 'alice@example.com');
        $this->assertArrayHasKey('token', $result);
        $this->assertArrayHasKey('id', $result);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $result['token']);
        $this->assertIsInt($result['id']);
    }

    public function testCreatedInvitationIsPending(): void
    {
        ['token' => $token] = $this->inv->create('user:1', 'alice@example.com');
        $item = $this->inv->find($token);
        $this->assertSame(InvitationToken::STATUS_PENDING, $item['status']);
    }

    public function testCreatedTokensAreDistinct(): void
    {
        ['token' => $t1] = $this->inv->create('user:1', 'a@example.com');
        ['token' => $t2] = $this->inv->create('user:1', 'b@example.com');
        $this->assertNotSame($t1, $t2);
    }

    // ── accept ───────────────────────────────────────────────────────────────

    public function testAcceptValidTokenReturnsInviterAndEmail(): void
    {
        ['token' => $token] = $this->inv->create('user:1', 'alice@example.com');
        $result = $this->inv->accept($token);
        $this->assertIsArray($result);
        $this->assertSame('user:1', $result['inviter_id']);
        $this->assertSame('alice@example.com', $result['email']);
    }

    public function testAcceptSetsStatusToAccepted(): void
    {
        ['token' => $token] = $this->inv->create('user:1', 'alice@example.com');
        $this->inv->accept($token);
        $item = $this->inv->find($token);
        $this->assertSame(InvitationToken::STATUS_ACCEPTED, $item['status']);
    }

    public function testAcceptUnknownTokenReturnsNull(): void
    {
        $this->assertNull($this->inv->accept(str_repeat('x', 64)));
    }

    public function testAcceptAlreadyAcceptedReturnsNull(): void
    {
        ['token' => $token] = $this->inv->create('user:1', 'alice@example.com');
        $this->inv->accept($token);
        $this->assertNull($this->inv->accept($token)); // second accept
    }

    public function testAcceptCancelledTokenReturnsNull(): void
    {
        ['token' => $token] = $this->inv->create('user:1', 'alice@example.com');
        $this->inv->cancel($token, 'user:1');
        $this->assertNull($this->inv->accept($token));
    }

    public function testAcceptExpiredTokenReturnsNull(): void
    {
        ['token' => $token] = $this->inv->create('user:1', 'alice@example.com');
        $this->db->exec("UPDATE invitations SET expires_at = '2000-01-01 00:00:00'");
        $this->assertNull($this->inv->accept($token));
    }

    public function testAcceptExpiryCheckedBeforeStatus(): void
    {
        // Expired + pending should return null (410 scenario), not mistakenly succeed
        ['token' => $token] = $this->inv->create('user:1', 'alice@example.com');
        $this->db->exec("UPDATE invitations SET expires_at = '2000-01-01 00:00:00'");
        // Status is still 'pending' — but expired wins
        $this->assertNull($this->inv->accept($token));
    }

    // ── cancel ───────────────────────────────────────────────────────────────

    public function testCancelByInviterReturnsTrue(): void
    {
        ['token' => $token] = $this->inv->create('user:1', 'alice@example.com');
        $this->assertTrue($this->inv->cancel($token, 'user:1'));
    }

    public function testCancelSetsStatusToCancelled(): void
    {
        ['token' => $token] = $this->inv->create('user:1', 'alice@example.com');
        $this->inv->cancel($token, 'user:1');
        $item = $this->inv->find($token);
        $this->assertSame(InvitationToken::STATUS_CANCELLED, $item['status']);
    }

    public function testCancelByWrongInviterReturnsFalse(): void
    {
        ['token' => $token] = $this->inv->create('user:1', 'alice@example.com');
        $this->assertFalse($this->inv->cancel($token, 'user:2'));
    }

    public function testCancelAlreadyAcceptedReturnsFalse(): void
    {
        ['token' => $token] = $this->inv->create('user:1', 'alice@example.com');
        $this->inv->accept($token);
        $this->assertFalse($this->inv->cancel($token, 'user:1'));
    }

    public function testCancelExpiredReturnsFalse(): void
    {
        ['token' => $token] = $this->inv->create('user:1', 'alice@example.com');
        $this->db->exec("UPDATE invitations SET expires_at = '2000-01-01 00:00:00'");
        $this->assertFalse($this->inv->cancel($token, 'user:1'));
    }

    public function testCancelUnknownTokenReturnsFalse(): void
    {
        $this->assertFalse($this->inv->cancel(str_repeat('x', 64), 'user:1'));
    }

    // ── find ─────────────────────────────────────────────────────────────────

    public function testFindReturnsInvitation(): void
    {
        ['token' => $token] = $this->inv->create('user:1', 'alice@example.com');
        $item = $this->inv->find($token);
        $this->assertIsArray($item);
        $this->assertSame('alice@example.com', $item['email']);
    }

    public function testFindUnknownTokenReturnsNull(): void
    {
        $this->assertNull($this->inv->find(str_repeat('x', 64)));
    }
}
