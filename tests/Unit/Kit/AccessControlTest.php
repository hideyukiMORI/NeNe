<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\AccessControl;
use PDO;
use PHPUnit\Framework\TestCase;

final class AccessControlTest extends TestCase
{
    private PDO $pdo;
    private AccessControl $ac;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE access_control (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                resource_type VARCHAR(100) NOT NULL,
                resource_id   VARCHAR(255) NOT NULL,
                subject_id    VARCHAR(255) NOT NULL,
                permission    VARCHAR(100) NOT NULL,
                granted_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (resource_type, resource_id, subject_id, permission)
            )
        ');
        $this->ac = new AccessControl($this->pdo);
    }

    // ── grant ─────────────────────────────────────────────────────────────────

    public function testGrantAddsPermission(): void
    {
        $this->ac->grant('doc', '42', 'user-1', 'write');
        $this->assertTrue($this->ac->can('doc', '42', 'user-1', 'write'));
    }

    public function testGrantIsIdempotent(): void
    {
        $this->ac->grant('doc', '42', 'user-1', 'write');
        $this->ac->grant('doc', '42', 'user-1', 'write');
        $this->assertSame(['write'], $this->ac->permissions('doc', '42', 'user-1'));
    }

    public function testGrantThrowsOnEmptyResourceType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ac->grant('', '42', 'user-1', 'read');
    }

    public function testGrantThrowsOnEmptyResourceId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ac->grant('doc', '', 'user-1', 'read');
    }

    public function testGrantThrowsOnEmptySubjectId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ac->grant('doc', '42', '', 'read');
    }

    public function testGrantThrowsOnEmptyPermission(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ac->grant('doc', '42', 'user-1', '');
    }

    // ── revoke ────────────────────────────────────────────────────────────────

    public function testRevokeDeletesPermission(): void
    {
        $this->ac->grant('doc', '42', 'user-1', 'read');
        $result = $this->ac->revoke('doc', '42', 'user-1', 'read');
        $this->assertTrue($result);
        $this->assertFalse($this->ac->can('doc', '42', 'user-1', 'read'));
    }

    public function testRevokeReturnsFalseWhenNotGranted(): void
    {
        $this->assertFalse($this->ac->revoke('doc', '42', 'user-1', 'read'));
    }

    public function testRevokeDoesNotAffectOtherPermissions(): void
    {
        $this->ac->grant('doc', '42', 'user-1', 'read');
        $this->ac->grant('doc', '42', 'user-1', 'write');
        $this->ac->revoke('doc', '42', 'user-1', 'read');

        $this->assertFalse($this->ac->can('doc', '42', 'user-1', 'read'));
        $this->assertTrue($this->ac->can('doc', '42', 'user-1', 'write'));
    }

    // ── revokeAll ─────────────────────────────────────────────────────────────

    public function testRevokeAllDeletesAllPermissions(): void
    {
        $this->ac->grant('doc', '42', 'user-1', 'read');
        $this->ac->grant('doc', '42', 'user-1', 'write');

        $n = $this->ac->revokeAll('doc', '42', 'user-1');
        $this->assertSame(2, $n);
        $this->assertSame([], $this->ac->permissions('doc', '42', 'user-1'));
    }

    // ── can ───────────────────────────────────────────────────────────────────

    public function testCanReturnsFalseWhenNotGranted(): void
    {
        $this->assertFalse($this->ac->can('doc', '42', 'user-1', 'read'));
    }

    public function testCanIsolatedByResource(): void
    {
        $this->ac->grant('doc', '1', 'user-1', 'write');
        $this->assertFalse($this->ac->can('doc', '2', 'user-1', 'write'));
    }

    // ── permissions ───────────────────────────────────────────────────────────

    public function testPermissionsListsAllGranted(): void
    {
        $this->ac->grant('doc', '42', 'user-1', 'read');
        $this->ac->grant('doc', '42', 'user-1', 'write');

        $perms = $this->ac->permissions('doc', '42', 'user-1');
        $this->assertSame(['read', 'write'], $perms);
    }

    public function testPermissionsReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->ac->permissions('doc', '42', 'user-1'));
    }

    // ── subjects ──────────────────────────────────────────────────────────────

    public function testSubjectsListsGranted(): void
    {
        $this->ac->grant('doc', '42', 'user-1', 'read');
        $this->ac->grant('doc', '42', 'user-2', 'read');
        $this->ac->grant('doc', '42', 'user-3', 'write');

        $readers = $this->ac->subjects('doc', '42', 'read');
        $this->assertSame(['user-1', 'user-2'], $readers);
    }

    // ── aclFor ────────────────────────────────────────────────────────────────

    public function testAclForReturnsAllEntries(): void
    {
        $this->ac->grant('doc', '42', 'user-1', 'read');
        $this->ac->grant('doc', '42', 'user-2', 'write');

        $acl = $this->ac->aclFor('doc', '42');
        $this->assertCount(2, $acl);
        $this->assertArrayHasKey('subject_id', $acl[0]);
    }

    public function testAclForReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->ac->aclFor('doc', '99'));
    }

    // ── clearResource ─────────────────────────────────────────────────────────

    public function testClearResourceDeletesAllEntries(): void
    {
        $this->ac->grant('doc', '1', 'user-1', 'read');
        $this->ac->grant('doc', '1', 'user-2', 'write');
        $this->ac->grant('doc', '2', 'user-1', 'read');

        $n = $this->ac->clearResource('doc', '1');
        $this->assertSame(2, $n);
        $this->assertSame([], $this->ac->aclFor('doc', '1'));
        $this->assertCount(1, $this->ac->aclFor('doc', '2'));
    }
}
