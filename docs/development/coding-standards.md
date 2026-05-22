# Coding Standards

NeNe is a legacy PHP framework, but new work should move the codebase toward current stable PHP and common PHP standards.

## PHP Compatibility

- Treat PHP 8.4 in the Docker application container as the current development target.
- Support the latest stable PHP version as far as practical.
- Keep compatibility decisions explicit in Issues and ADRs.
- Avoid new code that depends on deprecated PHP behavior.
- Prefer strict types for new PHP files.
- Use typed parameters, return types, and properties when they improve clarity and do not break compatibility.

## Composer Packages

- Keep direct Composer dependencies on current stable versions where practical.
- Remove unused packages instead of upgrading them.
- Use `composer update vendor/package --with-dependencies` for targeted dependency work when possible.
- Do not commit `vendor/`.
- Run `composer validate --strict` after dependency or Composer metadata changes.
- Run `composer install --dry-run` to confirm lock file consistency.

## PSR and Style

- Follow PSR-4 autoloading.
- Follow PSR-12 coding style.
- Use PHP CS Fixer as the primary formatter when formatting is needed.
- Do not mix formatting-only changes into unrelated PRs.
- Preserve the existing namespace layout unless an Issue and ADR approve a migration.
- Preserve legacy uppercase properties only where they are part of existing framework surface. New private/protected implementation details should use normal camelCase names unless compatibility requires the legacy style.

PHP CS Fixer is configured in `.php-cs-fixer.dist.php`.

Check formatting without changing files:

```sh
composer format:check
```

Apply formatting in a dedicated formatting PR:

```sh
composer format
```

`composer check` does not run PHP CS Fixer yet because the legacy tree still needs a focused formatting pass. Add it only after the baseline formatting PR lands.

## PHPDoc

PHPDoc should be useful and accurate.

- Document public and protected classes, methods, and properties.
- Keep `@param`, `@return`, and `@throws` accurate.
- Prefer native PHP types when possible, with PHPDoc used for richer array shapes or domain meaning.
- Do not leave placeholder annotations such as `[type]`, `mixed` without context, or stale class names.
- Update PHPDoc when changing method behavior or return values.

## Security

Security fixes should be small, focused, and prioritized.

- Treat Dependabot alerts and known CVEs as high-priority maintenance work.
- Validate and sanitize input at the boundary.
- Escape output by default in templates.
- Avoid exposing stack traces, secrets, or local paths in production responses.
- Do not trust request headers or URL parameters.
- Avoid dynamic class, method, file, or template resolution unless constrained by framework conventions.
- Keep sessions, authentication, and authorization behavior explicit.
- Never commit secrets or local environment files.

## REST Controllers

REST endpoints should make accepted HTTP methods explicit.

- Prefer method-specific controller methods such as `indexGetRest`, `indexPostRest`, `itemPutRest`, and `itemDeleteRest`.
- Avoid new `{action}Rest` handlers because they accept every HTTP method through the legacy dispatcher fallback.
- Use `{action}Rest` only when there is a documented compatibility or framework-level reason to accept all methods.
- Authentication and state-changing endpoints must use method-specific REST handlers.
- Build REST payloads through `Nene\Xion\ApiResponse` instead of hand-writing response arrays in controllers.
- Success payloads use `status: success` and `errorCode: ""`; failure payloads use `status: failure`, `errorCode`, and `errorMessage`.
- Error codes must be stable uppercase kebab-case strings with a domain prefix, such as `LOGIN-FAILED` or `TODO-NOT-FOUND`.
- Define error code messages and HTTP status values in the server-side catalog at `config/error_codes.php`; controllers should reference codes, not inline messages or direct HTTP status headers.
- Treat browser-visible error-code assets as exports or compatibility files, not the source of truth.

## Action Method Precedence

When resolving a URL, the dispatcher (`class/xion/Dispatcher.php::resolveActionRoute`) looks for handler methods on the controller in the following order:

1. `{action}{Verb}Rest` — e.g. `itemGetRest`, `itemPostRest` (method-specific REST)
2. `{action}Rest` — legacy verb-agnostic REST handler
3. `{action}Action` — server-rendered HTML handler

The first match wins. **There is no `Accept`-header content negotiation.** A request to `/foo/bar` with `GET` resolves to `barGetRest()` if it exists, regardless of whether the client asked for `text/html` or `application/json`.

A consequence worth flagging:

- **Do not define `{action}{Verb}Rest` and `{action}Action` for the same `action` on the same controller.** The HTML caller will receive JSON because the REST handler wins. If a single URL needs both shapes, split them — for example, `/notes/index` (HTML) and `/notes/list` (JSON) — or branch on `$this->method` inside `actionAction()` and emit JSON manually only for explicit API verbs.
- The `*Rest` and `*Action` *can* coexist on the same controller for **different** actions (most controllers do this). The restriction is only per `action` name + verb.
- Surveyed and confirmed in FT7 (`docs/field-trials/2026-05-field-trial-7.md` F-4): trying to mix shapes silently breaks the FT5 unauthenticated-HTML redirect because the REST handler is preferred and returns a JSON 401 envelope instead.

The HTML controller review checklist (`docs/review/html-controller.md`) already encodes the recommendation; this section explains the underlying dispatcher rule so reviewers know what they are enforcing.

## URL Parameter Format

NeNe encodes URL parameters as `key_value` path segments after the controller and action, separated by underscores. This is a legacy-preserved convention — NeNe intentionally keeps the older PHP framework shape rather than adopting modern REST `{id}` templates.

For example, the route `/todo/item/id_42` resolves to:

- Controller: `TodoController`
- Action: `item` → method `itemGetRest` / `itemPutRest` / `itemDeleteRest` depending on the HTTP method
- URL parameter: `id = 42`

Inside the controller method, read the value via `Request::getParam($key)`:

```php
$id = $this->request->getParam('id');
if ($id === null || !ctype_digit((string)$id)) {
    return $this->API_RESPONSE->failure('TODO-ID-REQUIRED');
}
$id = (int)$id;
```

Multiple parameters use additional `key_value` segments. `/foo/bar/page_3/sort_name` resolves to `page = 3` and `sort = name`.

A few consequences worth flagging when generating routes (by hand or with an AI agent):

- The path on the wire is `/{controller}/{action}/id_X`, not `/{controller}/{action}/{id}`.
- OpenAPI `paths` keys use the literal underscore form (`/todo/item/id_{id}`) so the documented and runtime paths stay aligned.
- Query strings (`?key=value`) still work via `Request::getQuery($key)` when filter-style parameters are needed instead.
- A REST client written against a `{id}` template will hit 404 because the dispatcher takes only the first two URL segments as controller and action.

When in doubt, read `class/xion/UrlParameter.php` for the parsing logic and existing samples in `class/controller/TodoController.php`.

## Service and Use-Case Logic

Controllers are HTTP boundaries. Keep business decisions in service/use-case code once a controller method does more than simple request parsing, mapper delegation, and response selection.

Use the existing `Nene\Model\` namespace under `class/model/` for application service classes when a feature needs a separate business-logic boundary. This uses the current Composer autoload map and does not require a new dispatcher path or framework abstraction.

Controller responsibilities:

- Read request JSON, route parameters, query values, and session/auth state.
- Call a mapper for very small CRUD behavior, or call a service when the use case has business rules.
- Convert service results into `ApiResponse` success or failure payloads.
- Set template values for server-rendered pages.

Service/use-case responsibilities:

- Express one application operation with a clear method name, such as `createArticle()` or `completeTodoForUser()`.
- Validate business rules that are not purely HTTP shape checks.
- Coordinate multiple mappers, multiple SQL statements, or side effects.
- Own the `TransactionManager::run()` boundary when one logical operation must commit or roll back as a unit.
- Return plain domain/application data, not HTTP responses, Smarty templates, or raw controller payload envelopes.

Mapper responsibilities:

- Keep SQL and database row conversion inside mapper classes.
- Do not know about HTTP request shape, templates, or `ApiResponse`.
- Do not start transactions for every statement; leave transaction boundaries to service/use-case code.

### Junction tables (many-to-many)

`DataMapperBase` assumes a single-column primary key (the `KEY_SID` constant) and exposes id-centric helpers such as `find($sid)`, `update($model)`, and `delete($model)`. These helpers are **not** designed for many-to-many junction tables like `article_tags (article_id, tag_id)`, where the natural primary key is composite.

For junction tables:

- Still inherit from `DataMapperBase` to keep PDO acquisition (`$this->DB`) and the logger (`$this->LOGGER`) consistent with the rest of the codebase.
- Leave `KEY_SID` unset — do not invent a synthetic id just to satisfy the base class.
- Do not use `find()` / `update()` / `delete()` from the base class; they assume single-column PK.
- Write the relation operations (find ids by left, replace tag set, clear all rows for one parent) as small raw prepared statements through `$this->execute($stmt)`.
- Keep schema-level cascade behavior (`ON DELETE CASCADE` in MySQL / `FOREIGN KEY ... ON DELETE CASCADE` in SQLite) on the junction table so the relation rows disappear when either side is hard-deleted.
- When the parent uses soft delete (`is_deleted = 1`), the cascade does not fire automatically; clear the junction rows explicitly inside the same `TransactionManager::run()` block as the parent's soft-delete write.
- Callers (controllers or service code) own the transaction boundary when more than one statement must commit atomically — for example a parent update that re-syncs the relation set.

## OpenAPI

New public HTTP APIs should be documented with OpenAPI.

- REST-style controller methods should have an OpenAPI contract before or alongside implementation.
- Keep request and response schemas explicit.
- Prefer JSON responses for API endpoints.
- Keep OpenAPI files in `docs/api/` unless a future ADR chooses another location.

## Database Transactions

`Nene\Xion\TransactionManager` is the canonical transaction boundary for NeNe application code. Humans and AI agents should use this pattern when adding multi-step database writes.

Data mapper methods must keep `DataMapperBase::execute()` as a single-statement execution boundary. Do not start and commit a transaction inside every `execute()` call.

Use `TransactionManager` at the service, controller, or use-case boundary when one logical operation needs multiple writes, multiple SQL statements, or multiple mappers:

```php
$transaction = new TransactionManager();
$transaction->run(function () use ($userMapper, $profileMapper): void {
    $userMapper->create($user);
    $profileMapper->create($profile);
});
```

The transaction manager commits when the callback succeeds and rolls back when it throws. If a transaction is already active, it leaves the outer transaction responsible for the final commit or rollback.

Do not create an alternate transaction helper for new features unless an Issue and ADR explain why `TransactionManager` is insufficient.

### Surfacing domain errors from inside a transaction

A `TransactionManager::run()` callback that throws a plain `Throwable` rolls back the transaction, but the exception then escapes to `htdocs/index.php`'s catch-all and produces a plain-text 500 response. That is the wrong shape for domain-level failures such as "referenced row not found" or "duplicate name".

For these cases, throw `Nene\Xion\DomainException` with a registered error code. The top-level handler in `htdocs/index.php` catches it and converts to the standard JSON failure envelope with the HTTP status declared in `config/error_codes.php`:

```php
use Nene\Xion\DomainException;
use Nene\Xion\TransactionManager;

$transaction = new TransactionManager();
$transaction->run(function () use ($bookmarkMapper, $junctionMapper, $tagIds): void {
    foreach ($tagIds as $tagId) {
        if (!$tagMapper->exists($tagId)) {
            throw new DomainException('BOOKMARK-TAG-IDS-INVALID');
        }
    }
    $junctionMapper->replaceTagsForBookmark($bookmarkId, $tagIds);
});
```

When this throws, `TransactionManager` rolls back, the top-level handler emits `ApiResponse::failure('BOOKMARK-TAG-IDS-INVALID')`, and the client sees the normal JSON envelope plus the 400 (or whichever HTTP status the catalog declares) from `config/error_codes.php`.

The error code must already exist in `config/error_codes.php`. Throwing an unknown code falls back to the generic 500 path, which is intentional — domain errors are part of the API contract and must be registered first.

For purely pre-flight input validation (no DB writes), prefer returning `$this->API_RESPONSE->failure($code)` directly from the controller before opening the transaction. `DomainException` is for cases where the validation must run alongside the writes, for example a row-existence check whose target may change between the validation and the write.

## Testing and Static Analysis

- At minimum, run PHP syntax checks for changed PHP files.
- Use Composer validation for dependency and package metadata changes.
- Use Phan where practical. If existing baseline issues block adoption, record the limitation in the PR and create follow-up Issues.
- Add focused tests when adding behavior that can be tested without large framework setup.

## Legacy Compatibility

NeNe has legacy conventions and global configuration constants. Do not rewrite them opportunistically.

When improving old code:

- Keep behavior compatible unless the Issue explicitly asks for a breaking change.
- Prefer small migrations over broad rewrites.
- Use ADRs for compatibility policy changes.
