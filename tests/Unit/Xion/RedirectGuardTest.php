<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\HttpTermination;
use Nene\Xion\RedirectGuard;
use PHPUnit\Framework\TestCase;

/**
 * RedirectGuard enforces the open-redirect allowlist that protects
 * `ControllerBase::location($uri, false)` callers (#408).
 *
 * Default policy is fail-closed: when `NENE_ALLOWED_EXTERNAL_REDIRECTS`
 * is empty or unset, every external host is rejected. This is the safer
 * default for a small framework where a misconfigured controller could
 * otherwise forward a user-supplied `return_to` URL into a phishing
 * destination.
 */
final class RedirectGuardTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('NENE_ALLOWED_EXTERNAL_REDIRECTS');
    }

    protected function tearDown(): void
    {
        putenv('NENE_ALLOWED_EXTERNAL_REDIRECTS');
    }

    public function testEmptyEnvDeniesEveryExternalHost(): void
    {
        self::assertFalse(RedirectGuard::isExternalAllowed('https://example.com/path'));
    }

    public function testAllowsHostInExplicitAllowlist(): void
    {
        putenv('NENE_ALLOWED_EXTERNAL_REDIRECTS=example.com,partner.example.org');
        self::assertTrue(RedirectGuard::isExternalAllowed('https://example.com/welcome'));
        self::assertTrue(RedirectGuard::isExternalAllowed('https://partner.example.org/login'));
    }

    public function testDeniesHostMissingFromAllowlist(): void
    {
        putenv('NENE_ALLOWED_EXTERNAL_REDIRECTS=example.com');
        self::assertFalse(RedirectGuard::isExternalAllowed('https://evil.example.net/'));
    }

    public function testMatchIsCaseInsensitive(): void
    {
        putenv('NENE_ALLOWED_EXTERNAL_REDIRECTS=Example.COM');
        self::assertTrue(RedirectGuard::isExternalAllowed('https://EXAMPLE.com/'));
    }

    public function testRejectsUriWithoutHost(): void
    {
        putenv('NENE_ALLOWED_EXTERNAL_REDIRECTS=example.com');
        self::assertFalse(RedirectGuard::isExternalAllowed('/relative-path'));
        self::assertFalse(RedirectGuard::isExternalAllowed(''));
    }

    public function testEnsureExternalAllowedThrowsOnDeny(): void
    {
        $this->expectException(HttpTermination::class);
        RedirectGuard::ensureExternalAllowed('https://evil.example.net/');
    }

    public function testEnsureExternalAllowedPassesOnAllow(): void
    {
        putenv('NENE_ALLOWED_EXTERNAL_REDIRECTS=example.com');
        RedirectGuard::ensureExternalAllowed('https://example.com/safe');
        self::assertTrue(true);
    }

    public function testAllowedHostsTrimsAndFiltersEmptyEntries(): void
    {
        putenv('NENE_ALLOWED_EXTERNAL_REDIRECTS= example.com , , partner.example.org ');
        self::assertSame(['example.com', 'partner.example.org'], RedirectGuard::allowedHosts());
    }
}
