# API Versioning

NeNe uses URI prefix versioning (`/v1/`, `/v2/`). Each API version is a set of independent
controller classes with their own routes. Deprecated versions emit RFC 8594 deprecation
signal headers via `ApiDeprecation::sendHeaders()`.

See ADR-0013 for the decision rationale.

## URI prefix pattern

Register each API version as a separate route group in your router config:

```php
// routes.php
'/v1/notes'        => ['controller' => 'v1\NoteController', 'method' => 'GET'],
'/v1/notes/create' => ['controller' => 'v1\NoteController', 'method' => 'POST'],
'/v2/notes'        => ['controller' => 'v2\NoteController', 'method' => 'GET'],
'/v2/notes/create' => ['controller' => 'v2\NoteController', 'method' => 'POST'],
```

Each version's controller lives in its own directory:

```
class/controller/v1/NoteController.php
class/controller/v2/NoteController.php
```

## Creating a new version

1. Create a `class/controller/v2/` directory for the new version.
2. Copy (or derive from) the v1 controller and apply the breaking change.
3. Register the `/v2/` routes in the router config.
4. Mark the v1 controller as deprecated (see below).

## Deprecating an old version

Add `ApiDeprecation::sendHeaders()` to `preAction()` in the deprecated controller:

```php
<?php
// class/controller/v1/NoteController.php
declare(strict_types=1);

namespace Nene\Controller\V1;

use Nene\Xion\ControllerBase;
use Nene\Kit\ApiDeprecation;

final class NoteController extends ControllerBase
{
    protected function preAction(): void
    {
        ApiDeprecation::sendHeaders(
            sunsetDate: '2027-01-01',
            successor:  '/v2/notes',
        );
    }

    // ... existing action methods unchanged ...
}
```

This emits three RFC 8594 headers on every response from that controller:

```
Deprecation: true
Sunset: Wed, 01 Jan 2027 23:59:59 GMT
Link: </v2/notes>; rel="successor-version"
```

API clients, gateways, and SDKs that implement RFC 8594 will surface the deprecation
to developers automatically.

## Backward compatibility via response transformation

When v2 changes a field name or shape, keep the v1 response stable with a private
transformation method:

```php
// v2/NoteController.php  — returns {id, body, updatedAt}
// v1/NoteController.php  — still returns {id, content, updated_at}

private function toV1(array $note): array
{
    return [
        'id'         => $note['id'],
        'content'    => $note['body'],        // renamed in v2
        'updated_at' => $note['updatedAt'],   // snake_case in v1
    ];
}
```

Do not share model or mapper classes between versions unless they are truly identical.
Shared code creates hidden coupling that makes future versions harder to change.

## Removing a retired version

Once the Sunset date has passed:

1. Remove the deprecated controller directory (`class/controller/v1/`).
2. Remove the `/v1/` routes from the router config.
3. Update `docs/adr/0013-api-versioning.md` to note the retired version.

Do not remove a version before the Sunset date — clients may still be using it.

## Related

- `class/kit/ApiDeprecation.php` — RFC 8594 header helper
- `docs/adr/0013-api-versioning.md` — versioning strategy ADR
- `docs/development/json-only-api.md` — JSON API conventions
