<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Func;

use Nene\Func\RateLimiter;
use Nene\Func\RateLimiterStorageInterface;
use Nene\Func\RedisRateLimiterStorage;
use Nene\Xion\HttpTermination;
use PHPUnit\Framework\TestCase;
use Predis\Client;

/**
 * In-memory storage for testing. Lives here so it doesn't leak into production autoload.
 */
final class ArrayRateLimiterStorage implements RateLimiterStorageInterface
{
    /** @var array<string,int> */
    private array $counts = [];
    /** @var array<string,int> */
    private array $ttls   = [];

    public function increment(string $key, int $windowSeconds): int
    {
        $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;
        $this->ttls[$key]   = $windowSeconds;
        return $this->counts[$key];
    }

    public function ttl(string $key): int
    {
        return $this->ttls[$key] ?? 0;
    }

    public function count(string $key): int
    {
        return $this->counts[$key] ?? 0;
    }
}

final class RateLimiterTest extends TestCase
{
    // -------------------------------------------------------------------
    // RateLimiter::check() — within limit
    // -------------------------------------------------------------------

    /**
     * @runInSeparateProcess
     */
    public function testCheckWithinLimitDoesNotThrow(): void
    {
        $storage = new ArrayRateLimiterStorage();
        $limiter = new RateLimiter($storage);

        // Should not throw when count (1) <= limit (5)
        $limiter->check('test:key', 5, 60);

        // If we reach here the check passed
        self::assertTrue(true);
    }

    /**
     * headers_list() returns an empty array under the CLI SAPI, so we cannot
     * assert exact header values here. Instead we verify that check() completes
     * without throwing (header emission is covered by the code path being
     * exercised) and that the storage received the correct increment call.
     */
    public function testCheckSetsRateLimitHeadersWithoutThrowing(): void
    {
        $storage = new ArrayRateLimiterStorage();
        $limiter = new RateLimiter($storage);

        $limiter->check('test:key', 5, 60);

        // count should be 1 after one check
        self::assertSame(1, $storage->count('test:key'));
    }

    // -------------------------------------------------------------------
    // RateLimiter::check() — exceeds limit
    // -------------------------------------------------------------------

    /**
     * @runInSeparateProcess
     */
    public function testCheckExceedingLimitThrowsHttpTermination(): void
    {
        $storage = new ArrayRateLimiterStorage();
        $limiter = new RateLimiter($storage);

        // Burn through all 3 allowed requests
        $limiter->check('busy:key', 3, 60);
        $limiter->check('busy:key', 3, 60);
        $limiter->check('busy:key', 3, 60);

        // 4th request must throw
        $this->expectException(HttpTermination::class);
        $limiter->check('busy:key', 3, 60);
    }

    /**
     * @runInSeparateProcess
     */
    public function testCheckExceedingLimitThrowsWith429Response(): void
    {
        $storage = new ArrayRateLimiterStorage();
        $limiter = new RateLimiter($storage);

        $limiter->check('busy:key', 1, 60);

        try {
            $limiter->check('busy:key', 1, 60);
            self::fail('Expected HttpTermination was not thrown.');
        } catch (HttpTermination $e) {
            self::assertSame(429, $e->response()->statusCode());
        }
    }

    // -------------------------------------------------------------------
    // RateLimiter::remaining()
    // -------------------------------------------------------------------

    public function testRemainingReturnsLimitMinusCount(): void
    {
        $storage = new ArrayRateLimiterStorage();
        // Simulate 3 prior hits
        $storage->increment('key', 60);
        $storage->increment('key', 60);
        $storage->increment('key', 60);

        $limiter = new RateLimiter($storage);
        self::assertSame(7, $limiter->remaining('key', 10));
    }

    public function testRemainingReturnsZeroWhenCountExceedsLimit(): void
    {
        $storage = new ArrayRateLimiterStorage();
        // Simulate 15 hits against a limit of 10
        for ($i = 0; $i < 15; $i++) {
            $storage->increment('key', 60);
        }

        $limiter = new RateLimiter($storage);
        self::assertSame(0, $limiter->remaining('key', 10));
    }

    public function testRemainingReturnsFullLimitWhenNoRequests(): void
    {
        $storage = new ArrayRateLimiterStorage();
        $limiter = new RateLimiter($storage);

        self::assertSame(10, $limiter->remaining('new:key', 10));
    }

    // -------------------------------------------------------------------
    // RedisRateLimiterStorage — unit tests with Predis mock
    // -------------------------------------------------------------------

    public function testRedisStorageIncrementFirstRequestSetsExpiry(): void
    {
        $redis = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['__call'])
            ->getMock();

        $callLog = [];
        $redis->method('__call')
            ->willReturnCallback(function (string $method, array $args) use (&$callLog) {
                $callLog[] = ['method' => $method, 'args' => $args];
                return match ($method) {
                    'incr'   => 1,
                    'expire' => true,
                    'ttl'    => 60,
                    'get'    => null,
                    default  => null,
                };
            });

        $storage = new RedisRateLimiterStorage($redis);
        $result  = $storage->increment('rate:key', 60);

        self::assertSame(1, $result);
        self::assertSame('incr',   $callLog[0]['method']);
        self::assertSame('expire', $callLog[1]['method']);
        self::assertSame(['rate:key', 60], $callLog[1]['args']);
    }

    public function testRedisStorageIncrementSubsequentRequestSkipsExpiry(): void
    {
        $redis = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['__call'])
            ->getMock();

        $callLog = [];
        $redis->method('__call')
            ->willReturnCallback(function (string $method, array $args) use (&$callLog) {
                $callLog[] = ['method' => $method, 'args' => $args];
                return match ($method) {
                    'incr'   => 2,
                    'expire' => true,
                    default  => null,
                };
            });

        $storage = new RedisRateLimiterStorage($redis);
        $result  = $storage->increment('rate:key', 60);

        self::assertSame(2, $result);

        $methods = array_column($callLog, 'method');
        self::assertContains('incr',     $methods);
        self::assertNotContains('expire', $methods);
    }

    public function testRedisStorageTtlReturnsNonNegative(): void
    {
        $redis = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['__call'])
            ->getMock();

        $redis->method('__call')
            ->willReturnCallback(fn (string $method) => match ($method) {
                'ttl'  => -1,
                default => null,
            });

        $storage = new RedisRateLimiterStorage($redis);
        self::assertSame(0, $storage->ttl('missing:key'));
    }

    public function testRedisStorageCountReturnsZeroForMissingKey(): void
    {
        $redis = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['__call'])
            ->getMock();

        $redis->method('__call')
            ->willReturn(null);

        $storage = new RedisRateLimiterStorage($redis);
        self::assertSame(0, $storage->count('absent:key'));
    }
}
