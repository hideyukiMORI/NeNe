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

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * DB-backed circuit breaker for external service calls.
 *
 * States:
 *   CLOSED    — Normal operation. Failures are counted.
 *   OPEN      — All calls rejected immediately (no network). After
 *               $openSeconds, transitions to HALF-OPEN.
 *   HALF-OPEN — One probe call allowed. Success → CLOSED. Failure → OPEN.
 *
 * Schema (one-time migration):
 *   CREATE TABLE circuit_breaker_states (
 *       name         VARCHAR(100) NOT NULL PRIMARY KEY,
 *       state        VARCHAR(20)  NOT NULL DEFAULT 'closed',
 *       failures     INT          NOT NULL DEFAULT 0,
 *       opened_at    DATETIME     NULL,
 *       updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 *   );
 *
 * Usage:
 *   $cb = new CircuitBreaker('payment-api');
 *   if ($cb->isAvailable()) {
 *       try {
 *           $response = $httpClient->post('/charge', $payload);
 *           $cb->recordSuccess();
 *       } catch (\Exception $e) {
 *           $cb->recordFailure();
 *           throw $e;
 *       }
 *   } else {
 *       throw new ServiceUnavailableException('Payment API circuit is open');
 *   }
 */
final class CircuitBreaker
{
    public const STATE_CLOSED    = 'closed';
    public const STATE_OPEN      = 'open';
    public const STATE_HALF_OPEN = 'half_open';

    private const DEFAULT_TABLE = 'circuit_breaker_states';

    private PDO $db;

    public function __construct(
        private readonly string $name,
        ?PDO $db = null,
        private readonly int $failureThreshold = 5,
        private readonly int $openSeconds = 60,
        private readonly string $table = self::DEFAULT_TABLE,
    ) {
        $this->db = $db ?? PdoConnection::getInstance();
    }

    /**
     * Whether a call should be attempted.
     *
     * Returns true for CLOSED (always) and HALF-OPEN (one probe).
     * Returns false for OPEN, unless enough time has passed to try HALF-OPEN.
     */
    public function isAvailable(?int $now = null): bool
    {
        $state = $this->loadState();
        $currentTime = $now ?? time();

        if ($state === null || $state['state'] === self::STATE_CLOSED) {
            return true;
        }

        if ($state['state'] === self::STATE_HALF_OPEN) {
            return true;
        }

        // STATE_OPEN: check if cooldown has elapsed
        if ($state['opened_at'] !== null) {
            $openedAt = strtotime($state['opened_at']);
            if ($openedAt !== false && ($currentTime - $openedAt) >= $this->openSeconds) {
                // Transition to HALF-OPEN
                $this->setState(self::STATE_HALF_OPEN, $state['failures'], $state['opened_at']);
                return true;
            }
        }

        return false;
    }

    /**
     * Record a successful call.
     *
     * Resets failure count and closes the circuit regardless of current state.
     */
    public function recordSuccess(): void
    {
        $this->setState(self::STATE_CLOSED, 0, null);
    }

    /**
     * Record a failed call.
     *
     * Increments failure count. Opens the circuit if the count reaches
     * $failureThreshold.
     */
    public function recordFailure(?int $now = null): void
    {
        $state    = $this->loadState();
        $failures = ($state !== null ? (int) $state['failures'] : 0) + 1;
        $openedAt = $state['opened_at'] ?? null;

        if ($failures >= $this->failureThreshold) {
            $openedAt = date('Y-m-d H:i:s', $now ?? time());
            $this->setState(self::STATE_OPEN, $failures, $openedAt);
        } else {
            $this->setState(self::STATE_CLOSED, $failures, $openedAt);
        }
    }

    /**
     * Return the current state string.
     *
     * Returns STATE_CLOSED if no record exists (default state).
     */
    public function getState(): string
    {
        $state = $this->loadState();
        return $state !== null ? (string) $state['state'] : self::STATE_CLOSED;
    }

    /**
     * Return the current failure count (0 if no record exists).
     */
    public function failureCount(): int
    {
        $state = $this->loadState();
        return $state !== null ? (int) $state['failures'] : 0;
    }

    /**
     * Manually reset the circuit to CLOSED (e.g., admin action).
     */
    public function reset(): void
    {
        $this->setState(self::STATE_CLOSED, 0, null);
    }

    // -----------------------------------------------------------------------

    /**
     * @return array<string,mixed>|null
     */
    private function loadState(): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT state, failures, opened_at FROM {$this->table} WHERE name = ?"
        );
        $stmt->execute([$this->name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    private function setState(string $state, int $failures, ?string $openedAt): void
    {
        $driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $sql = "INSERT OR REPLACE INTO {$this->table} (name, state, failures, opened_at, updated_at)
                    VALUES (?, ?, ?, ?, datetime('now'))";
        } else {
            $sql = "INSERT INTO {$this->table} (name, state, failures, opened_at, updated_at)
                    VALUES (?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE
                        state      = VALUES(state),
                        failures   = VALUES(failures),
                        opened_at  = VALUES(opened_at),
                        updated_at = NOW()";
        }
        $this->db->prepare($sql)->execute([$this->name, $state, $failures, $openedAt]);
    }
}
