<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\RedisSessionHandler;
use Nene\Xion\SessionHandlerFactory;
use PHPUnit\Framework\TestCase;

/**
 * SessionHandlerFactory unit tests.
 *
 * These tests verify DSN parsing logic only. No live Redis connection is
 * made — the factory builds a Predis client but does not connect until
 * the first Redis command is issued.
 */
final class SessionHandlerFactoryTest extends TestCase
{
    public function testEmptyDsnReturnsNull(): void
    {
        self::assertNull(SessionHandlerFactory::fromDsn(''));
    }

    public function testRedisDsnReturnsRedisSessionHandler(): void
    {
        $handler = SessionHandlerFactory::fromDsn('redis://127.0.0.1:6379');
        self::assertInstanceOf(RedisSessionHandler::class, $handler);
    }

    public function testRedisDsnWithDatabaseReturnsRedisSessionHandler(): void
    {
        $handler = SessionHandlerFactory::fromDsn('redis://redis:6379/2');
        self::assertInstanceOf(RedisSessionHandler::class, $handler);
    }

    public function testRedisDsnHostOnly(): void
    {
        $handler = SessionHandlerFactory::fromDsn('redis://redis');
        self::assertInstanceOf(RedisSessionHandler::class, $handler);
    }

    public function testUnknownSchemeReturnsNull(): void
    {
        // Unknown scheme: falls back gracefully (no exception), returns null.
        $handler = SessionHandlerFactory::fromDsn('memcached://localhost:11211');
        self::assertNull($handler);
    }
}
