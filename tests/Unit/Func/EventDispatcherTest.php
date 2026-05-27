<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Func;

use Nene\Func\EventDispatcher;
use PHPUnit\Framework\TestCase;

final class EventDispatcherTest extends TestCase
{
    protected function tearDown(): void
    {
        EventDispatcher::reset();
    }

    // --- listen / emit ---

    public function testListenerReceivesPayload(): void
    {
        $received = null;
        EventDispatcher::listen('test.event', function (array $p) use (&$received): void {
            $received = $p;
        });

        EventDispatcher::emit('test.event', ['key' => 'value']);

        self::assertSame(['key' => 'value'], $received);
    }

    public function testMultipleListenersFiredInOrder(): void
    {
        $order = [];
        EventDispatcher::listen('order.test', function (array $p) use (&$order): void {
            $order[] = 'first';
        });
        EventDispatcher::listen('order.test', function (array $p) use (&$order): void {
            $order[] = 'second';
        });
        EventDispatcher::listen('order.test', function (array $p) use (&$order): void {
            $order[] = 'third';
        });

        EventDispatcher::emit('order.test');

        self::assertSame(['first', 'second', 'third'], $order);
    }

    public function testEmitWithNoListenersDoesNothing(): void
    {
        $errors = EventDispatcher::emit('no.listeners');
        self::assertSame([], $errors);
    }

    public function testEmitWithEmptyPayloadDefaultsToEmptyArray(): void
    {
        $received = 'not-called';
        EventDispatcher::listen('empty.payload', function (array $p) use (&$received): void {
            $received = $p;
        });

        EventDispatcher::emit('empty.payload');

        self::assertSame([], $received);
    }

    // --- stopOnError: true (default) ---

    public function testExceptionPropagatesWhenStopOnErrorTrue(): void
    {
        $secondCalled = false;
        EventDispatcher::listen('err.event', function (): void {
            throw new \RuntimeException('boom');
        });
        EventDispatcher::listen('err.event', function () use (&$secondCalled): void {
            $secondCalled = true;
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');

        EventDispatcher::emit('err.event');

        self::assertFalse($secondCalled, 'Second listener must not run');
    }

    // --- stopOnError: false ---

    public function testCollectErrorsWhenStopOnErrorFalse(): void
    {
        $ran = [];
        EventDispatcher::listen('multi.err', function () use (&$ran): void {
            $ran[] = 'a';
            throw new \RuntimeException('err-a');
        });
        EventDispatcher::listen('multi.err', function () use (&$ran): void {
            $ran[] = 'b';
            throw new \LogicException('err-b');
        });
        EventDispatcher::listen('multi.err', function () use (&$ran): void {
            $ran[] = 'c';
        });

        $errors = EventDispatcher::emit('multi.err', [], stopOnError: false);

        self::assertSame(['a', 'b', 'c'], $ran, 'All listeners should run');
        self::assertCount(2, $errors);
        self::assertInstanceOf(\RuntimeException::class, $errors[0]);
        self::assertInstanceOf(\LogicException::class, $errors[1]);
    }

    public function testReturnsEmptyArrayWhenNoErrors(): void
    {
        EventDispatcher::listen('ok.event', function (): void {
        });
        $errors = EventDispatcher::emit('ok.event', [], stopOnError: false);
        self::assertSame([], $errors);
    }

    // --- listeners() ---

    public function testListenersReturnsRegisteredCallables(): void
    {
        $cb1 = function (): void {
        };
        $cb2 = function (): void {
        };
        EventDispatcher::listen('q', $cb1);
        EventDispatcher::listen('q', $cb2);

        $list = EventDispatcher::listeners('q');
        self::assertCount(2, $list);
        self::assertSame($cb1, $list[0]);
        self::assertSame($cb2, $list[1]);
    }

    public function testListenersReturnsEmptyForUnknownEvent(): void
    {
        self::assertSame([], EventDispatcher::listeners('unknown'));
    }

    // --- forget ---

    public function testForgetRemovesListenersForEvent(): void
    {
        EventDispatcher::listen('a', function (): void {
        });
        EventDispatcher::listen('b', function (): void {
        });

        EventDispatcher::forget('a');

        self::assertSame([], EventDispatcher::listeners('a'));
        self::assertCount(1, EventDispatcher::listeners('b'));
    }

    public function testForgetNullRemovesAllListeners(): void
    {
        EventDispatcher::listen('a', function (): void {
        });
        EventDispatcher::listen('b', function (): void {
        });

        EventDispatcher::forget();

        self::assertSame([], EventDispatcher::listeners('a'));
        self::assertSame([], EventDispatcher::listeners('b'));
    }

    // --- reset ---

    public function testResetClearsAll(): void
    {
        EventDispatcher::listen('x', function (): void {
        });
        EventDispatcher::reset();
        self::assertSame([], EventDispatcher::listeners('x'));
    }

    // --- same-event multiple listeners, payload mutation ---

    public function testListenerCannotMutatePayloadForNextListener(): void
    {
        // Payload is passed by value (PHP array copy semantics)
        $seenBySecond = null;
        EventDispatcher::listen('immutable', function (array $p): void {
            $p['extra'] = 'added';   // local copy only
        });
        EventDispatcher::listen('immutable', function (array $p) use (&$seenBySecond): void {
            $seenBySecond = $p;
        });

        EventDispatcher::emit('immutable', ['original' => true]);

        self::assertArrayNotHasKey('extra', $seenBySecond ?? []);
    }
}
