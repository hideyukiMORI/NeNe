<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\RouteContext;
use PHPUnit\Framework\TestCase;

final class RouteContextTest extends TestCase
{
    public function testSetUpdatesCurrentRoute(): void
    {
        $routeContext = RouteContext::getInstance();

        $routeContext->set('todo', 'index', 'Rest', 'indexGetRest');

        self::assertSame('todo', $routeContext->controller());
        self::assertSame('index', $routeContext->action());
        self::assertSame('Rest', $routeContext->mode());
        self::assertSame('indexGetRest', $routeContext->method());
        self::assertTrue($routeContext->isRest());
        self::assertFalse($routeContext->isAction());
    }

    public function testSetCanReplaceRouteWithoutRedefiningGlobals(): void
    {
        $routeContext = RouteContext::getInstance();

        $routeContext->set('index', 'index', 'Action', 'indexAction');
        $routeContext->set('session', 'login', 'Rest', 'loginPostRest');

        self::assertSame('session', $routeContext->controller());
        self::assertSame('login', $routeContext->action());
        self::assertSame('Rest', $routeContext->mode());
        self::assertSame('loginPostRest', $routeContext->method());
    }
}
