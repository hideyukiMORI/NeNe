<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\RedisSessionHandler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Predis\ClientInterface;

/**
 * RedisSessionHandler unit tests.
 *
 * The Predis client is mocked — no live Redis connection is needed.
 */
final class RedisSessionHandlerTest extends TestCase
{
    private ClientInterface&MockObject $redis;
    private RedisSessionHandler $handler;

    protected function setUp(): void
    {
        $this->redis = $this->createMock(ClientInterface::class);
        $this->handler = new RedisSessionHandler($this->redis, ttl: 900);
    }

    public function testOpenAlwaysReturnsTrue(): void
    {
        self::assertTrue($this->handler->open('/tmp', 'PHPSESSID'));
    }

    public function testCloseAlwaysReturnsTrue(): void
    {
        self::assertTrue($this->handler->close());
    }

    public function testReadReturnsDataForExistingKey(): void
    {
        $this->redis->expects(self::once())
            ->method('__call')
            ->with('get', ['sess_abc123'])
            ->willReturn('serialized|data');

        $result = $this->handler->read('abc123');
        self::assertSame('serialized|data', $result);
    }

    public function testReadReturnsEmptyStringWhenKeyMissing(): void
    {
        $this->redis->expects(self::once())
            ->method('__call')
            ->with('get', ['sess_missing'])
            ->willReturn(null);

        $result = $this->handler->read('missing');
        self::assertSame('', $result);
    }

    public function testWriteCallsSetexWithCorrectTtlAndKey(): void
    {
        $this->redis->expects(self::once())
            ->method('__call')
            ->with('setex', ['sess_xyz789', 900, 'payload']);

        $result = $this->handler->write('xyz789', 'payload');
        self::assertTrue($result);
    }

    public function testDestroyDeletesKey(): void
    {
        $this->redis->expects(self::once())
            ->method('__call')
            ->with('del', [['sess_deadbeef']]);

        $result = $this->handler->destroy('deadbeef');
        self::assertTrue($result);
    }

    public function testGcIsNoopAndReturnsZero(): void
    {
        // Redis handles expiry via TTL; gc() should never call the client.
        $this->redis->expects(self::never())->method('__call');

        $result = $this->handler->gc(1440);
        self::assertSame(0, $result);
    }
}
