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

use Predis\ClientInterface;

/**
 * Redis-backed PHP session handler.
 *
 * Implements PHP's native SessionHandlerInterface so it can be registered
 * with session_set_save_handler() before session_start(). Session data is
 * stored as plain strings under the key "sess_{id}" with a TTL matching the
 * PHP session.gc_maxlifetime ini value passed at construction time.
 *
 * Redis TTL handles garbage collection natively — the gc() method is a no-op.
 *
 * Usage (via SessionHandlerFactory):
 *   $handler = SessionHandlerFactory::fromDsn(SESSION_DSN);
 *   if ($handler !== null) {
 *       session_set_save_handler($handler, true);
 *   }
 *
 * @see SessionHandlerFactory
 * @see https://www.php.net/manual/en/class.sessionhandlerinterface.php
 */
class RedisSessionHandler implements \SessionHandlerInterface
{
    private const KEY_PREFIX = 'sess_';

    /**
     * @param ClientInterface $redis Predis client connected to the Redis instance.
     * @param int             $ttl   Session lifetime in seconds. Defaults to the
     *                               session.gc_maxlifetime ini value (typically 1440 s).
     */
    public function __construct(
        private readonly ClientInterface $redis,
        private readonly int $ttl = 1440,
    ) {
    }

    /**
     * Called on session_start(). No-op: the client is pre-connected.
     */
    public function open(string $path, string $name): bool
    {
        return true;
    }

    /**
     * Called when the session write is complete. No-op.
     */
    public function close(): bool
    {
        return true;
    }

    /**
     * Read session data by session ID.
     *
     * Returns an empty string when the key does not exist (new or expired
     * session), which is what PHP expects for an uninitialized session.
     */
    public function read(string $id): string|false
    {
        $data = $this->redis->get(self::KEY_PREFIX . $id);
        return $data === null ? '' : (string)$data;
    }

    /**
     * Write session data. Refreshes the TTL on every write so active sessions
     * do not expire while the user is actively using the application.
     */
    public function write(string $id, string $data): bool
    {
        $this->redis->setex(self::KEY_PREFIX . $id, $this->ttl, $data);
        return true;
    }

    /**
     * Delete a session by ID (called on session_destroy()).
     */
    public function destroy(string $id): bool
    {
        $this->redis->del([self::KEY_PREFIX . $id]);
        return true;
    }

    /**
     * Garbage collection. Redis handles expiry via key TTL natively, so
     * this method is intentionally a no-op. PHP will still call it
     * periodically based on session.gc_probability / session.gc_divisor.
     *
     * @return int|false Returns 0 (no rows cleaned up) to satisfy the interface.
     */
    public function gc(int $maxLifetime): int|false
    {
        return 0;
    }
}
