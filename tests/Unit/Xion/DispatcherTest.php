<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\Dispatcher;
use PHPUnit\Framework\TestCase;

final class DispatcherTest extends TestCase
{
    public function testResolveActionRoutePrefersHttpMethodRestHandler(): void
    {
        $controller = new class {
            public function indexGetRest(): array
            {
                return [];
            }

            public function indexPostRest(): array
            {
                return [];
            }
        };

        $route = (new Dispatcher())->resolveActionRoute($controller, 'index', 'GET');

        self::assertSame(200, $route['status']);
        self::assertSame('Rest', $route['mode']);
        self::assertSame('indexGetRest', $route['method']);
        self::assertSame(['GET', 'POST'], $route['allowed']);
    }

    public function testResolveActionRouteKeepsLegacyRestFallback(): void
    {
        $controller = new class {
            public function loginRest(): array
            {
                return [];
            }
        };

        $route = (new Dispatcher())->resolveActionRoute($controller, 'login', 'POST');

        self::assertSame(200, $route['status']);
        self::assertSame('Rest', $route['mode']);
        self::assertSame('loginRest', $route['method']);
    }

    public function testResolveActionRouteReturnsMethodNotAllowedWhenOnlyOtherRestMethodsExist(): void
    {
        $controller = new class {
            public function itemPutRest(): array
            {
                return [];
            }

            public function itemDeleteRest(): array
            {
                return [];
            }
        };

        $route = (new Dispatcher())->resolveActionRoute($controller, 'item', 'GET');

        self::assertSame(405, $route['status']);
        self::assertSame(['DELETE', 'PUT'], $route['allowed']);
    }
}
