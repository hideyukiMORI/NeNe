<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\HttpTermination;
use Nene\Xion\RoleGuard;
use PHPUnit\Framework\TestCase;

final class RoleGuardTest extends TestCase
{
    // --- has() ---

    public function testHasReturnsTrueWhenRoleMatches(): void
    {
        self::assertTrue(RoleGuard::has('admin', ['role' => 'admin']));
    }

    public function testHasReturnsFalseWhenRoleDiffers(): void
    {
        self::assertFalse(RoleGuard::has('admin', ['role' => 'user']));
    }

    public function testHasReturnsFalseWhenRoleClaimAbsent(): void
    {
        self::assertFalse(RoleGuard::has('admin', []));
    }

    public function testHasReturnsFalseWhenRoleClaimIsNotString(): void
    {
        self::assertFalse(RoleGuard::has('admin', ['role' => 123]));
    }

    // --- require() ---

    public function testRequirePassesWhenRoleMatches(): void
    {
        RoleGuard::require('admin', ['role' => 'admin']);
        self::assertTrue(true); // no exception
    }

    public function testRequireThrowsWhenRoleDiffers(): void
    {
        $this->expectException(HttpTermination::class);
        RoleGuard::require('admin', ['role' => 'user']);
    }

    public function testRequireThrowsWhenRoleClaimAbsent(): void
    {
        $this->expectException(HttpTermination::class);
        RoleGuard::require('admin', []);
    }

    // --- requireAny() ---

    public function testRequireAnyPassesWhenFirstRoleMatches(): void
    {
        RoleGuard::requireAny(['editor', 'admin'], ['role' => 'editor']);
        self::assertTrue(true);
    }

    public function testRequireAnyPassesWhenSecondRoleMatches(): void
    {
        RoleGuard::requireAny(['editor', 'admin'], ['role' => 'admin']);
        self::assertTrue(true);
    }

    public function testRequireAnyThrowsWhenNoRoleMatches(): void
    {
        $this->expectException(HttpTermination::class);
        RoleGuard::requireAny(['editor', 'admin'], ['role' => 'user']);
    }

    public function testRequireAnyThrowsWhenRoleClaimAbsent(): void
    {
        $this->expectException(HttpTermination::class);
        RoleGuard::requireAny(['editor', 'admin'], []);
    }
}
