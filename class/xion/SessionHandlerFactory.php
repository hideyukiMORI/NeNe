<?php

/**
 * AYANE : ayane.co.jp
 * powered by NENE.
 *
 * PHP Version >= 8.4
 *
 * @package   AYANE
 * @author    hideyukiMORI <info@ayane.co.jp>
 * @copyright 2021 AYANE
 * @license   https://opensource.org/licenses/MIT MIT License
 * @link      https://ayane.co.jp/
 */

declare(strict_types=1);

namespace Nene\Xion;

use Predis\Client as PredisClient;

/**
 * Factory that constructs the appropriate session handler from a DSN string.
 *
 * Supported DSN schemes:
 *   - redis://[host][:port][/db]  → RedisSessionHandler backed by Predis
 *   - (empty string)              → returns null; PHP default file handler is used
 *
 * The factory is the single place where SESSION_DSN is parsed. Call it once
 * in the front controller before session_start():
 *
 *   $handler = SessionHandlerFactory::fromDsn(SESSION_DSN);
 *   if ($handler !== null) {
 *       session_set_save_handler($handler, true);
 *   }
 *
 * @see RedisSessionHandler
 */
class SessionHandlerFactory
{
    /**
     * Build a session handler from a DSN, or return null to use PHP defaults.
     *
     * @param string $dsn  SESSION_DSN value from xSystemIni.php.
     *                     Empty string → null (PHP file sessions).
     *                     "redis://..." → RedisSessionHandler.
     *
     * @return \SessionHandlerInterface|null
     */
    public static function fromDsn(string $dsn): ?\SessionHandlerInterface
    {
        if ($dsn === '') {
            return null;
        }

        if (str_starts_with($dsn, 'redis://')) {
            return self::buildRedisHandler($dsn);
        }

        // Unknown scheme — log a warning and fall back to file sessions rather
        // than crashing the request. Surfaced as F-3 during FT18.
        error_log('NeNe SessionHandlerFactory: unrecognised SESSION_DSN scheme: ' . $dsn);
        return null;
    }

    /**
     * Parse a redis:// DSN and construct a RedisSessionHandler.
     *
     * DSN format: redis://[host][:port][/db]
     * Examples:
     *   redis://redis:6379
     *   redis://127.0.0.1:6379/1
     *   redis://redis          (port defaults to 6379)
     */
    private static function buildRedisHandler(string $dsn): RedisSessionHandler
    {
        $parsed = parse_url($dsn);

        $params = [
            'scheme' => 'tcp',
            'host'   => (string)($parsed['host'] ?? '127.0.0.1'),
            'port'   => (int)($parsed['port'] ?? 6379),
        ];

        $path = (string)($parsed['path'] ?? '');
        if ($path !== '' && $path !== '/') {
            $params['database'] = (int)ltrim($path, '/');
        }

        $client = new PredisClient($params);

        $ttl = (int)(ini_get('session.gc_maxlifetime') ?: 1440);

        return new RedisSessionHandler($client, $ttl);
    }
}
