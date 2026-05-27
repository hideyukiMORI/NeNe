<?php

declare(strict_types=1);

namespace Tests\Unit\Xion;

use Nene\Xion\HttpCache;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Nene\Xion\HttpCache (FT44).
 *
 * header() is a no-op in CLI, so tests for methods that only emit headers
 * are smoke tests that assert no exception is thrown.
 * send304() calls exit and is excluded from tests.
 */
class HttpCacheTest extends TestCase
{
    // ------------------------------------------------------------------ //
    // sendCacheControl()
    // ------------------------------------------------------------------ //

    public function testSendCacheControlPublicDoesNotThrow(): void
    {
        HttpCache::sendCacheControl(maxAge: 60);
        $this->assertTrue(true);
    }

    public function testSendCacheControlPrivateDoesNotThrow(): void
    {
        HttpCache::sendCacheControl(maxAge: 300, private: true);
        $this->assertTrue(true);
    }

    public function testSendCacheControlNoStoreDoesNotThrow(): void
    {
        HttpCache::sendCacheControl(noStore: true);
        $this->assertTrue(true);
    }

    public function testSendCacheControlImmutableDoesNotThrow(): void
    {
        HttpCache::sendCacheControl(maxAge: 86400, immutable: true);
        $this->assertTrue(true);
    }

    // ------------------------------------------------------------------ //
    // sendLastModified()
    // ------------------------------------------------------------------ //

    public function testSendLastModifiedWithDatetimeStringDoesNotThrow(): void
    {
        HttpCache::sendLastModified('2026-01-15 10:30:00');
        $this->assertTrue(true);
    }

    public function testSendLastModifiedWithTimestampDoesNotThrow(): void
    {
        HttpCache::sendLastModified(time());
        $this->assertTrue(true);
    }

    public function testSendLastModifiedWithInvalidStringDoesNotThrow(): void
    {
        // Malformed string is silently ignored — no exception, no header.
        HttpCache::sendLastModified('not-a-date');
        $this->assertTrue(true);
    }

    // ------------------------------------------------------------------ //
    // sendNoCache()
    // ------------------------------------------------------------------ //

    public function testSendNoCacheDoesNotThrow(): void
    {
        HttpCache::sendNoCache();
        $this->assertTrue(true);
    }

    // ------------------------------------------------------------------ //
    // isNotModified()
    // ------------------------------------------------------------------ //

    public function testIsNotModifiedReturnsFalseWhenNoHeader(): void
    {
        $result = HttpCache::isNotModified('2026-01-15 10:30:00', null);
        $this->assertFalse($result);
    }

    public function testIsNotModifiedReturnsFalseWhenHeaderBeforeLastModified(): void
    {
        // Client has older copy — resource is newer.
        // Jan 14 2026 = Wednesday; Jan 15 2026 = Thursday.
        $result = HttpCache::isNotModified(
            '2026-01-15 10:30:00',
            'Wed, 14 Jan 2026 10:30:00 GMT',
        );
        $this->assertFalse($result);
    }

    public function testIsNotModifiedReturnsTrueWhenHeaderMatchesLastModified(): void
    {
        // Same timestamp — client cache is still valid.
        // Jan 15 2026 = Thursday.
        $result = HttpCache::isNotModified(
            '2026-01-15 10:30:00',
            'Thu, 15 Jan 2026 10:30:00 GMT',
        );
        $this->assertTrue($result);
    }

    public function testIsNotModifiedReturnsTrueWhenHeaderAfterLastModified(): void
    {
        // Client has equal or newer copy.
        // Jan 16 2026 = Friday.
        $result = HttpCache::isNotModified(
            '2026-01-15 10:30:00',
            'Fri, 16 Jan 2026 10:30:00 GMT',
        );
        $this->assertTrue($result);
    }

    public function testIsNotModifiedReturnsFalseForInvalidHeader(): void
    {
        $result = HttpCache::isNotModified('2026-01-15 10:30:00', 'not-a-valid-date');
        $this->assertFalse($result);
    }

    public function testIsNotModifiedWithIntTimestamp(): void
    {
        $resourceTs = mktime(10, 30, 0, 1, 15, 2026);
        assert($resourceTs !== false);
        $clientTs = $resourceTs + 3600; // client has a newer copy
        $result = HttpCache::isNotModified($resourceTs, gmdate('D, d M Y H:i:s', $clientTs) . ' GMT');
        $this->assertTrue($result);
    }

    public function testIsNotModifiedWithEmptyHeaderReturnsFalse(): void
    {
        $result = HttpCache::isNotModified('2026-01-15 10:30:00', '');
        $this->assertFalse($result);
    }
}
