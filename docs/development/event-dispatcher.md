# Event Dispatcher

`Nene\Func\EventDispatcher` is a lightweight in-process publish-subscribe event dispatcher. It decouples the emitter of an event from the listeners that react to it, without requiring an external message broker or background queue.

## When to use

Use `EventDispatcher` when you need to:

- Notify multiple subsystems of a domain event (user registered, order placed, etc.) without coupling them to each other
- Run side-effects (email, audit log, cache invalidation) after a core action, without littering that action with unrelated code
- Build a plugin or hook system where modules register behaviour dynamically

For cross-process or persistent queues, use a dedicated message broker instead.

## API

```php
use Nene\Func\EventDispatcher;
```

| Method | Description |
| --- | --- |
| `EventDispatcher::listen(string $event, callable $listener): void` | Register a listener for the given event. Multiple listeners per event are allowed; fired FIFO. |
| `EventDispatcher::emit(string $event, array $payload = [], bool $stopOnError = true): array` | Emit an event, invoking all registered listeners. Returns collected `\Throwable`s when `$stopOnError = false`; always `[]` when `true`. |
| `EventDispatcher::listeners(string $event): array` | Return all registered listeners for the event (useful in tests). |
| `EventDispatcher::forget(?string $event = null): void` | Remove all listeners for one event, or all events when `null`. |
| `EventDispatcher::reset(): void` | Clear the entire registry. Use in test `tearDown()`. |

## Registering listeners

Call `listen()` at bootstrap or in a service-provider equivalent (e.g. `ini/xSystemIni.php` or a controller's `preAction()`):

```php
// Send a welcome e-mail when a user registers
EventDispatcher::listen('user.registered', function (array $payload): void {
    Mailer::sendWelcome($payload['email']);
});

// Write an audit log entry for the same event
EventDispatcher::listen('user.registered', function (array $payload): void {
    AuditLog::write('user.registered', $payload['userId']);
});
```

Listeners fire in **registration order** (FIFO). If priority matters, register higher-priority listeners first.

## Emitting events

```php
// Emit with a payload
EventDispatcher::emit('user.registered', [
    'userId' => 42,
    'email'  => 'taro@example.com',
]);

// Emit with no payload (listeners receive an empty array)
EventDispatcher::emit('cache.cleared');
```

`emit()` returns an empty array when `$stopOnError = true` (the default) — exceptions from listeners propagate immediately.

## Listener signature

Every listener receives one argument: the `$payload` array passed to `emit()`.

```php
EventDispatcher::listen('order.placed', function (array $payload): void {
    // $payload is a local copy — mutations do not affect other listeners
    $orderId = $payload['orderId'];
});
```

**Payload immutability**: PHP arrays have copy-on-assign semantics. Mutations inside one listener are invisible to the next.

Return values from listeners are ignored.

## Error handling

### Default (fail-fast)

By default, the first exception thrown by any listener propagates immediately, aborting further dispatch:

```php
EventDispatcher::listen('job.run', function (): void {
    throw new \RuntimeException('step failed');
});
EventDispatcher::listen('job.run', function (): void {
    // never reached if the first listener throws
});

EventDispatcher::emit('job.run'); // throws \RuntimeException
```

### Collect all errors

Pass `stopOnError: false` to run all listeners regardless of failures, and collect exceptions in the return value:

```php
$errors = EventDispatcher::emit('report.generated', $payload, stopOnError: false);

foreach ($errors as $e) {
    Logger::error($e->getMessage());
}
```

This is useful for fan-out notifications where one failing notifier should not block others.

## Removing listeners

```php
// Remove all listeners for one event
EventDispatcher::forget('cache.cleared');

// Remove listeners for every event
EventDispatcher::forget();
```

## Testing

Call `EventDispatcher::reset()` in `tearDown()` to prevent listener state from leaking between tests:

```php
protected function tearDown(): void
{
    EventDispatcher::reset();
}
```

Use `EventDispatcher::listeners('event.name')` to assert that the expected listeners were registered without actually emitting the event.

```php
EventDispatcher::listen('user.deleted', [$cleanup, 'handle']);
self::assertCount(1, EventDispatcher::listeners('user.deleted'));
```

## Related

- `Nene\Func\I18n` — message catalog; can be used inside listeners to build localised notifications.
- `Nene\Xion\HttpTermination` — use `emit()` before throwing termination to give listeners a chance to log or clean up.
