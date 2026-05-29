<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * RetrySchedule — exponential-backoff retry tracking for arbitrary operations.
 *
 * Tracks the retry state of any named operation (`ref`) — an outbound webhook,
 * a third-party sync, a flaky import — independent of how the work is
 * executed. `arm()` registers an operation, `backoff()` records a failed
 * attempt and computes the next eligible time with exponential backoff, and
 * `due()` lists operations ready to retry now. When attempts reach the
 * maximum the operation is marked exhausted (hand off to
 * {@see DeadLetterQueue} (FT274) at that point).
 *
 * Next attempt delay = `baseSeconds * 2^(attempts - 1)`, capped to keep the
 * exponent sane.
 *
 * ## Usage
 *
 * ```php
 * $rs = new RetrySchedule($pdo);
 *
 * $rs->arm('webhook:42', baseSeconds: 60, maxAttempts: 5); // due now
 *
 * // On failure: schedule the next attempt (60s, 120s, 240s, …)
 * $next = $rs->backoff('webhook:42'); // '2026-05-29 12:01:00' or null if exhausted
 *
 * // A worker polls for ready operations
 * foreach ($rs->due() as $r) { /* retry $r['ref'] *\/ }
 *
 * if ($rs->isExhausted('webhook:42')) { /* give up / dead-letter *\/ }
 *
 * $rs->clear('webhook:42'); // success or abandon
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE retry_schedule (
 *     id              INTEGER PRIMARY KEY AUTOINCREMENT,
 *     ref             VARCHAR(190) NOT NULL,
 *     attempts        INTEGER      NOT NULL DEFAULT 0,
 *     max_attempts    INTEGER      NOT NULL DEFAULT 5,
 *     base_seconds    INTEGER      NOT NULL DEFAULT 60,
 *     next_attempt_at DATETIME     NOT NULL,
 *     exhausted       INTEGER      NOT NULL DEFAULT 0,
 *     UNIQUE (ref)
 * );
 * ```
 */
final class RetrySchedule
{
    /** Maximum backoff exponent, to avoid overflow / absurd delays. */
    private const int MAX_EXPONENT = 16;

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Register (or reset) an operation for retry tracking; due immediately.
     *
     * @param  string      $ref         Operation identifier.
     * @param  int         $baseSeconds Base backoff unit (>= 1).
     * @param  int         $maxAttempts Maximum attempts before exhaustion (>= 1).
     * @param  string|null $asOf        Reference time; defaults to now.
     * @throws \InvalidArgumentException on empty ref or non-positive bounds.
     */
    public function arm(string $ref, int $baseSeconds = 60, int $maxAttempts = 5, ?string $asOf = null): void
    {
        $ref = $this->validateRef($ref);
        if ($baseSeconds < 1) {
            throw new \InvalidArgumentException('Base seconds must be at least 1.');
        }
        if ($maxAttempts < 1) {
            throw new \InvalidArgumentException('Max attempts must be at least 1.');
        }
        $now = $this->ts($asOf);

        DbUpsert::run(
            $this->db(),
            table:        'retry_schedule',
            data:         [
                'ref'             => $ref,
                'attempts'        => 0,
                'max_attempts'    => $maxAttempts,
                'base_seconds'    => $baseSeconds,
                'next_attempt_at' => $now,
                'exhausted'       => 0,
            ],
            conflictCols: ['ref'],
            updateCols:   ['attempts', 'max_attempts', 'base_seconds', 'next_attempt_at', 'exhausted'],
        );
    }

    /**
     * Record a failed attempt and schedule the next one with exponential backoff.
     *
     * @param  string      $ref  Operation identifier (must be armed).
     * @param  string|null $asOf Reference time; defaults to now.
     * @return string|null       Next attempt timestamp, or null if now exhausted.
     * @throws \InvalidArgumentException if $ref is not armed.
     */
    public function backoff(string $ref, ?string $asOf = null): ?string
    {
        $ref = $this->validateRef($ref);
        $row = $this->fetch($ref);
        if ($row === null) {
            throw new \InvalidArgumentException("Ref is not armed: {$ref}");
        }

        $attempts = (int)$row['attempts'] + 1;
        $max      = (int)$row['max_attempts'];

        if ($attempts >= $max) {
            $stmt = $this->db()->prepare(
                'UPDATE retry_schedule SET attempts = ?, exhausted = 1 WHERE ref = ?'
            );
            $stmt->execute([$attempts, $ref]);

            return null;
        }

        $base  = (int)$row['base_seconds'];
        $exp   = min($attempts - 1, self::MAX_EXPONENT);
        $delay = $base * (2 ** $exp);
        $next  = date('Y-m-d H:i:s', $this->epoch($asOf) + (int)$delay);

        $stmt = $this->db()->prepare(
            'UPDATE retry_schedule SET attempts = ?, next_attempt_at = ? WHERE ref = ?'
        );
        $stmt->execute([$attempts, $next, $ref]);

        return $next;
    }

    /**
     * List operations eligible to retry now (not exhausted, next attempt due),
     * soonest first.
     *
     * @param  string|null $asOf Reference time; defaults to now.
     * @return array<int,array{ref:string,attempts:int,next_attempt_at:string}>
     */
    public function due(?string $asOf = null): array
    {
        $stmt = $this->db()->prepare(
            'SELECT ref, attempts, next_attempt_at FROM retry_schedule
             WHERE exhausted = 0 AND next_attempt_at <= ?
             ORDER BY next_attempt_at ASC'
        );
        $stmt->execute([$this->ts($asOf)]);

        $out = [];
        /** @var array<string,mixed> $row */
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = [
                'ref'             => (string)$row['ref'],
                'attempts'        => (int)$row['attempts'],
                'next_attempt_at' => (string)$row['next_attempt_at'],
            ];
        }

        return $out;
    }

    /**
     * Number of recorded failed attempts for an operation (0 if unknown).
     */
    public function attempts(string $ref): int
    {
        $row = $this->fetch($this->validateRef($ref));

        return $row === null ? 0 : (int)$row['attempts'];
    }

    /**
     * Next scheduled attempt time, or null if unknown.
     */
    public function nextAttemptAt(string $ref): ?string
    {
        $row = $this->fetch($this->validateRef($ref));

        return $row === null ? null : (string)$row['next_attempt_at'];
    }

    /**
     * Whether an operation has exhausted its attempts.
     */
    public function isExhausted(string $ref): bool
    {
        $row = $this->fetch($this->validateRef($ref));

        return $row !== null && (int)$row['exhausted'] === 1;
    }

    /**
     * Remove an operation's retry state (success or abandonment). No-op if absent.
     */
    public function clear(string $ref): void
    {
        $ref  = $this->validateRef($ref);
        $stmt = $this->db()->prepare('DELETE FROM retry_schedule WHERE ref = ?');
        $stmt->execute([$ref]);
    }

    // ── private ───────────────────────────────────────────────────────────────

    /**
     * @return array<string,mixed>|null
     */
    private function fetch(string $ref): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT attempts, max_attempts, base_seconds, next_attempt_at, exhausted
             FROM retry_schedule WHERE ref = ?'
        );
        $stmt->execute([$ref]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private function epoch(?string $asOf): int
    {
        $ts = strtotime($asOf ?? 'now');
        if ($ts === false) {
            throw new \InvalidArgumentException('Invalid reference time.');
        }

        return $ts;
    }

    private function ts(?string $asOf): string
    {
        return date('Y-m-d H:i:s', $this->epoch($asOf));
    }

    private function validateRef(string $ref): string
    {
        $ref = trim($ref);
        if ($ref === '') {
            throw new \InvalidArgumentException('Ref must not be empty.');
        }

        return $ref;
    }

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
