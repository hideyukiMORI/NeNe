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

namespace Nene\Func;

/**
 * Lightweight in-process publish-subscribe event dispatcher.
 *
 * Decouples the emitter of an event from the listeners that react to it,
 * without requiring an external message broker or background queue.
 *
 * ## Basic usage
 *
 * ```php
 * // Register listeners (e.g. in a bootstrap/Service layer)
 * EventDispatcher::listen('user.registered', function (array $payload): void {
 *     // send welcome email
 * });
 *
 * EventDispatcher::listen('user.registered', function (array $payload): void {
 *     // create audit log entry
 * });
 *
 * // Emit from a controller or service
 * EventDispatcher::emit('user.registered', ['userId' => 42, 'email' => 'a@b.com']);
 * ```
 *
 * ## Listener signature
 *
 * Every listener receives one argument: the `$payload` array passed to `emit()`.
 * The return value is ignored.
 *
 * ## Error handling
 *
 * By default, exceptions thrown by listeners propagate immediately (fail-fast).
 * Set `$stopOnError = false` in `emit()` to continue dispatching remaining
 * listeners and collect exceptions in the returned array.
 *
 * ## Priority
 *
 * Listeners fire in **registration order** (FIFO). Higher-priority listeners
 * should be registered first.
 *
 * ## Scope
 *
 * All state is held in static properties — one shared registry per PHP process.
 * Call `EventDispatcher::reset()` in test tearDown() to isolate tests.
 */
final class EventDispatcher
{
    /**
     * Registered listeners keyed by event name.
     *
     * @var array<string, list<callable(array<string,mixed>): void>>
     */
    private static array $listeners = [];

    /**
     * Register a listener for the given event.
     *
     * Multiple calls with the same event name append listeners in order.
     *
     * @param string                           $event    Event name (e.g. 'user.registered').
     * @param callable(array<string,mixed>): void $listener Callable that receives the payload array.
     */
    public static function listen(string $event, callable $listener): void
    {
        self::$listeners[$event][] = $listener;
    }

    /**
     * Emit an event and invoke all registered listeners.
     *
     * When `$stopOnError` is true (default), the first listener exception
     * propagates immediately. When false, all listeners run and any thrown
     * exceptions are returned as a list.
     *
     * @param string                $event       Event name.
     * @param array<string, mixed>  $payload     Arbitrary data passed to every listener.
     * @param bool                  $stopOnError Stop on first exception (default) or collect all.
     *
     * @return list<\Throwable> Collected exceptions when $stopOnError is false; always empty when true.
     *
     * @throws \Throwable When $stopOnError is true and a listener throws.
     */
    public static function emit(
        string $event,
        array $payload = [],
        bool $stopOnError = true,
    ): array {
        $errors = [];

        foreach (self::$listeners[$event] ?? [] as $listener) {
            try {
                $listener($payload);
            } catch (\Throwable $e) {
                if ($stopOnError) {
                    throw $e;
                }
                $errors[] = $e;
            }
        }

        return $errors;
    }

    /**
     * Return all registered listeners for an event (useful for testing).
     *
     * @return list<callable(array<string,mixed>): void>
     */
    public static function listeners(string $event): array
    {
        return self::$listeners[$event] ?? [];
    }

    /**
     * Remove all listeners for the given event, or all events when null.
     */
    public static function forget(?string $event = null): void
    {
        if ($event === null) {
            self::$listeners = [];
        } else {
            unset(self::$listeners[$event]);
        }
    }

    /**
     * Reset all listeners and event registry.
     *
     * Intended for use in tests — call in tearDown() to isolate test state.
     */
    public static function reset(): void
    {
        self::$listeners = [];
    }
}
