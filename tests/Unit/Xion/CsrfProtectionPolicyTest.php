<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\CsrfProtectionPolicy;
use Nene\Xion\RouteContext;
use PHPUnit\Framework\TestCase;

final class CsrfProtectionPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        RouteContext::getInstance()->reset();
    }

    public function testRequiresTokenForLoggedInStateChangingRestRequest(): void
    {
        RouteContext::getInstance()->set('todo', 'index', 'Rest', 'indexPostRest');

        self::assertTrue((new CsrfProtectionPolicy())->requiresToken(RouteContext::getInstance(), 'POST', true));
    }

    public function testDoesNotRequireTokenForReadOnlyRestRequest(): void
    {
        RouteContext::getInstance()->set('todo', 'index', 'Rest', 'indexGetRest');

        self::assertFalse((new CsrfProtectionPolicy())->requiresToken(RouteContext::getInstance(), 'GET', true));
    }

    public function testDoesNotRequireTokenForServerRenderedAction(): void
    {
        RouteContext::getInstance()->set('index', 'index', 'Action', 'indexAction');

        self::assertFalse((new CsrfProtectionPolicy())->requiresToken(RouteContext::getInstance(), 'POST', true));
    }

    public function testDoesNotRequireTokenForLoginRestRequest(): void
    {
        RouteContext::getInstance()->set('session', 'login', 'Rest', 'loginPostRest');

        self::assertFalse((new CsrfProtectionPolicy())->requiresToken(RouteContext::getInstance(), 'POST', false));
    }

    public function testDoesNotRequireTokenWhenSessionIsNotLoggedIn(): void
    {
        RouteContext::getInstance()->set('todo', 'index', 'Rest', 'indexPostRest');

        self::assertFalse((new CsrfProtectionPolicy())->requiresToken(RouteContext::getInstance(), 'POST', false));
    }

    public function testDoesNotRequireTokenWhenAuthenticatedViaBearer(): void
    {
        // Bearer authentication is a stateless API surface (FT16 / ADR-0008).
        // The Bearer token itself is the proof of intent, so CSRF skips
        // even though the request is state-changing and "logged in".
        RouteContext::getInstance()->set('todo', 'index', 'Rest', 'indexPostRest');

        self::assertFalse((new CsrfProtectionPolicy())->requiresToken(
            RouteContext::getInstance(),
            'POST',
            true,    // isLoggedIn (Bearer counts as logged in)
            true     // isBearerAuthenticated
        ));
    }
}
