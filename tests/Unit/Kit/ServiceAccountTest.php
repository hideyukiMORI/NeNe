<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\ServiceAccount;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ServiceAccount.
 */
final class ServiceAccountTest extends TestCase
{
    private PDO $db;
    private ServiceAccount $sa;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE service_accounts (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                client_id   VARCHAR(64)  NOT NULL UNIQUE,
                secret_hash VARCHAR(64)  NOT NULL,
                name        VARCHAR(100) NOT NULL,
                scopes      TEXT         NOT NULL DEFAULT \'\',
                enabled     TINYINT(1)   NOT NULL DEFAULT 1,
                created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                rotated_at  DATETIME     NULL
            )
        ');
        $this->sa = new ServiceAccount($this->db);
    }

    // ── create ────────────────────────────────────────────────────────────────

    public function testCreateReturnsClientIdAndSecret(): void
    {
        [$clientId, $secret] = $this->sa->create('my-service');
        $this->assertStringStartsWith('sa_', $clientId);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $secret);
    }

    public function testCreateStoresScopes(): void
    {
        [$clientId] = $this->sa->create('pipeline', ['read:events', 'write:metrics']);
        $account     = $this->sa->find($clientId);
        $this->assertNotNull($account);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertStringContainsString('read:events', $account['scopes']);
    }

    public function testCreateThrowsOnEmptyName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sa->create('');
    }

    public function testCreateProducesUniqueClientIds(): void
    {
        [$id1] = $this->sa->create('svc-1');
        [$id2] = $this->sa->create('svc-2');
        $this->assertNotSame($id1, $id2);
    }

    // ── verify ────────────────────────────────────────────────────────────────

    public function testVerifyReturnsRecordForValidCredentials(): void
    {
        [$clientId, $secret] = $this->sa->create('my-service');
        $account             = $this->sa->verify($clientId, $secret);
        $this->assertNotNull($account);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('my-service', $account['name']);
    }

    public function testVerifyReturnsNullForWrongSecret(): void
    {
        [$clientId] = $this->sa->create('my-service');
        $this->assertNull($this->sa->verify($clientId, 'wrong-secret'));
    }

    public function testVerifyReturnsNullForUnknownClientId(): void
    {
        $this->assertNull($this->sa->verify('sa_unknown', 'any-secret'));
    }

    public function testVerifyReturnsNullWhenDisabled(): void
    {
        [$clientId, $secret] = $this->sa->create('my-service');
        $this->sa->disable($clientId);
        $this->assertNull($this->sa->verify($clientId, $secret));
    }

    // ── rotate ────────────────────────────────────────────────────────────────

    public function testRotateReturnsNewSecret(): void
    {
        [$clientId, $oldSecret] = $this->sa->create('my-service');
        $newSecret              = $this->sa->rotate($clientId);
        $this->assertNotNull($newSecret);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string)$newSecret);
        $this->assertNotSame($oldSecret, $newSecret);
    }

    public function testOldSecretInvalidAfterRotation(): void
    {
        [$clientId, $oldSecret] = $this->sa->create('my-service');
        $this->sa->rotate($clientId);
        $this->assertNull($this->sa->verify($clientId, $oldSecret));
    }

    public function testNewSecretValidAfterRotation(): void
    {
        [$clientId] = $this->sa->create('my-service');
        $newSecret  = $this->sa->rotate($clientId);
        $this->assertNotNull($this->sa->verify($clientId, (string)$newSecret));
    }

    public function testRotateReturnsNullForUnknownAccount(): void
    {
        $this->assertNull($this->sa->rotate('sa_unknown'));
    }

    // ── enable / disable ──────────────────────────────────────────────────────

    public function testEnableAndDisable(): void
    {
        [$clientId, $secret] = $this->sa->create('my-service');
        $this->sa->disable($clientId);
        $this->assertNull($this->sa->verify($clientId, $secret));
        $this->sa->enable($clientId);
        $this->assertNotNull($this->sa->verify($clientId, $secret));
    }

    public function testDisableReturnsFalseForUnknown(): void
    {
        $this->assertFalse($this->sa->disable('sa_unknown'));
    }

    // ── find / all / delete ───────────────────────────────────────────────────

    public function testFindReturnsNullForUnknown(): void
    {
        $this->assertNull($this->sa->find('sa_unknown'));
    }

    public function testAllReturnsAllAccounts(): void
    {
        $this->sa->create('svc-a');
        $this->sa->create('svc-b');
        $rows = $this->sa->all();
        $this->assertCount(2, $rows);
    }

    public function testDeleteRemovesAccount(): void
    {
        [$clientId] = $this->sa->create('my-service');
        $this->assertTrue($this->sa->delete($clientId));
        $this->assertNull($this->sa->find($clientId));
    }

    public function testDeleteReturnsFalseForUnknown(): void
    {
        $this->assertFalse($this->sa->delete('sa_unknown'));
    }
}
