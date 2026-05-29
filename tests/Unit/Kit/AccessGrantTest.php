<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\AccessGrant;
use PDO;
use PHPUnit\Framework\TestCase;

final class AccessGrantTest extends TestCase
{
    private PDO $pdo;
    private AccessGrant $ag;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE access_grants (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                granter_id    VARCHAR(255) NOT NULL,
                grantee_id    VARCHAR(255) NOT NULL,
                resource_type VARCHAR(100) NOT NULL,
                resource_id   VARCHAR(255) NOT NULL,
                permissions   TEXT         NOT NULL,
                status        VARCHAR(20)  NOT NULL DEFAULT \'active\',
                expires_at    DATETIME     NULL,
                granted_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->ag = new AccessGrant($this->pdo);
    }

    // ── grant ─────────────────────────────────────────────────────────────────

    public function testGrantReturnsId(): void
    {
        $id = $this->ag->grant('user-1', 'user-2', 'document', 'doc-42', ['read']);
        $this->assertGreaterThan(0, $id);
    }

    public function testGrantStoresPermissions(): void
    {
        $id  = $this->ag->grant('user-1', 'user-2', 'document', 'doc-42', ['read', 'comment']);
        $row = $this->ag->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $perms = json_decode((string)$row['permissions'], true);
        $this->assertContains('read', $perms);
        $this->assertContains('comment', $perms);
    }

    public function testGrantThrowsOnEmptyGranterId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ag->grant('', 'user-2', 'document', 'doc-42', ['read']);
    }

    public function testGrantThrowsOnEmptyGranteeId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ag->grant('user-1', '', 'document', 'doc-42', ['read']);
    }

    public function testGrantThrowsOnEmptyResourceType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ag->grant('user-1', 'user-2', '', 'doc-42', ['read']);
    }

    public function testGrantThrowsOnEmptyResourceId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ag->grant('user-1', 'user-2', 'document', '', ['read']);
    }

    public function testGrantThrowsOnEmptyPermissions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ag->grant('user-1', 'user-2', 'document', 'doc-42', []);
    }

    // ── get ───────────────────────────────────────────────────────────────────

    public function testGetReturnsNullForMissingId(): void
    {
        $this->assertNull($this->ag->get(9999));
    }

    // ── hasAccess ─────────────────────────────────────────────────────────────

    public function testHasAccessReturnsTrueForGrantedPermission(): void
    {
        $this->ag->grant('user-1', 'user-2', 'document', 'doc-42', ['read', 'comment']);
        $this->assertTrue($this->ag->hasAccess('user-2', 'document', 'doc-42', 'read'));
        $this->assertTrue($this->ag->hasAccess('user-2', 'document', 'doc-42', 'comment'));
    }

    public function testHasAccessReturnsFalseForUnlistedPermission(): void
    {
        $this->ag->grant('user-1', 'user-2', 'document', 'doc-42', ['read']);
        $this->assertFalse($this->ag->hasAccess('user-2', 'document', 'doc-42', 'write'));
    }

    public function testHasAccessReturnsFalseForRevokedGrant(): void
    {
        $id = $this->ag->grant('user-1', 'user-2', 'document', 'doc-42', ['read']);
        $this->ag->revoke($id);
        $this->assertFalse($this->ag->hasAccess('user-2', 'document', 'doc-42', 'read'));
    }

    public function testHasAccessReturnsFalseForExpiredGrant(): void
    {
        $id = $this->ag->grant('user-1', 'user-2', 'document', 'doc-42', ['read'], '2000-01-01 00:00:00');
        $this->assertFalse($this->ag->hasAccess('user-2', 'document', 'doc-42', 'read'));
    }

    public function testHasAccessReturnsTrueForNonExpiredGrant(): void
    {
        $this->ag->grant('user-1', 'user-2', 'document', 'doc-42', ['read'], '2099-12-31 23:59:59');
        $this->assertTrue($this->ag->hasAccess('user-2', 'document', 'doc-42', 'read'));
    }

    // ── revoke ────────────────────────────────────────────────────────────────

    public function testRevokeChangesStatus(): void
    {
        $id = $this->ag->grant('user-1', 'user-2', 'document', 'doc-42', ['read']);
        $this->assertTrue($this->ag->revoke($id));
        $row = $this->ag->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(AccessGrant::STATUS_REVOKED, $row['status']);
    }

    public function testRevokeReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->ag->revoke(9999));
    }

    // ── revokeAll ─────────────────────────────────────────────────────────────

    public function testRevokeAllRevokesMultipleGrants(): void
    {
        $this->ag->grant('user-1', 'user-2', 'document', 'doc-42', ['read']);
        $this->ag->grant('user-1', 'user-2', 'document', 'doc-42', ['write']);
        $this->assertSame(2, $this->ag->revokeAll('user-2', 'document', 'doc-42'));
        $this->assertFalse($this->ag->hasAccess('user-2', 'document', 'doc-42', 'read'));
    }

    // ── myGrants ──────────────────────────────────────────────────────────────

    public function testMyGrantsReturnsActiveGrants(): void
    {
        $this->ag->grant('user-1', 'user-2', 'document', 'doc-42', ['read']);
        $this->ag->grant('user-1', 'user-2', 'folder', 'folder-5', ['read', 'write']);
        $grants = $this->ag->myGrants('user-2');
        $this->assertCount(2, $grants);
    }

    public function testMyGrantsExcludesRevoked(): void
    {
        $id = $this->ag->grant('user-1', 'user-2', 'document', 'doc-42', ['read']);
        $this->ag->revoke($id);
        $this->assertCount(0, $this->ag->myGrants('user-2'));
    }

    // ── grantsIGave ───────────────────────────────────────────────────────────

    public function testGrantsIGaveReturnsActiveGrants(): void
    {
        $this->ag->grant('user-1', 'user-2', 'document', 'doc-42', ['read']);
        $this->ag->grant('user-1', 'user-3', 'document', 'doc-99', ['read']);
        $grants = $this->ag->grantsIGave('user-1');
        $this->assertCount(2, $grants);
    }

    public function testGrantsIGaveExcludesRevoked(): void
    {
        $id = $this->ag->grant('user-1', 'user-2', 'document', 'doc-42', ['read']);
        $this->ag->revoke($id);
        $this->assertCount(0, $this->ag->grantsIGave('user-1'));
    }
}
