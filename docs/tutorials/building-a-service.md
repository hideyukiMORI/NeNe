# Building a Service with NeNe

This tutorial shows the common implementation path for a small NeNe service.

Read this as a guide for a renovated legacy framework. NeNe intentionally keeps the familiar shape of older PHP frameworks: URL segments select a controller and action, HTML pages use `actionAction()` methods, and data access stays close to small mapper classes. The modern parts are added around that shape: method-specific REST handlers, API response catalogs, OpenAPI, tests, Docker, and explicit security defaults.

If you know frameworks such as CodeIgniter 2/3 or Zend Framework 1, the flow should feel familiar. The difference is that NeNe keeps the core much smaller and documents the modern safety rails you should add when building services.

Use it as a checklist when adding application behavior:

1. Add a server-rendered page when users need HTML.
2. Add method-specific REST handlers when React or another client needs JSON.
3. Add database mapper/model code when the feature stores data.
4. Add error codes, OpenAPI, and tests at the same time as the endpoint.

NeNe is intentionally small. Prefer explicit controller methods, clear data mappers, and focused tests over hidden framework behavior.

Treat `class/xion/` as framework core. Most page, REST, service, mapper, and model work should happen in the application-side namespaces such as `class/controller/`, `class/model/`, `class/db/`, and `class/func/`. Do not change dispatcher or core behavior for a normal service feature.

## Before You Start

Work from a GitHub Issue and a topic branch.

```sh
git checkout main
git pull --ff-only
git checkout -b feature/123-articles
```

Run the local Docker environment when you need HTTP or database behavior.

```sh
docker compose up --build
```

Useful checks:

```sh
composer test
composer analyze
NENE_HTTP_BASE_URL=http://localhost:8080 composer test:http
```

## Add a Fixed Page

Use an HTML action when the browser should receive a server-rendered page.

Example goal:

```text
GET /page/about
```

Create a controller:

```php
<?php

declare(strict_types=1);

namespace Nene\Controller;

use Nene\Xion\ControllerBase;

class PageController extends ControllerBase
{
    protected function preAction()
    {
        $this->SESSION_CHECK = false;
    }

    public function aboutAction(): void
    {
        $this->setTitle('About NeNe');
        $this->VIEW->setString('t_heading', 'About NeNe');
        $this->VIEW->setString('t_body', 'A small legacy PHP framework.');
    }
}
```

Add the template at:

```text
view/source/page/about.tpl
```

```smarty
{extends file='layout/app.tpl'}
{block name='content'}
                <section class="page-about">
                    <h1>{$t_heading}</h1>
                    <p>{$t_body}</p>
                </section>
{/block}
```

Optional page assets are discovered by convention. `ControllerBase::setCSS()` and `setJS()` check three locations in order — drop a file at any of them and it is auto-linked into the page without explicit registration:

| Path | Loaded for |
| --- | --- |
| `htdocs/css/{controller}.css` | every action of `{controller}` |
| `htdocs/css/{controller}/common.css` | every action of `{controller}` |
| `htdocs/css/{controller}/{action}.css` | only that one action |

The same three patterns apply to `htdocs/js/...` for JavaScript. Files are emitted into the layout's `{foreach $t_css}` / `{foreach $t_js}` blocks with a cache-busting query string. Manual `addCSS()` / `addJS()` calls on `$this->VIEW` remain available when you need a CDN URL or a file outside the convention.

The dispatcher resolves `/page/about` to `PageController::aboutAction()`. `ControllerBase` automatically chooses `view/source/page/about.tpl` when it exists.

URL controller segments must be a **single lowercase word** (`page`, `note`, `bookmark`), not kebab-case (`private-note`, `bookmark-tag`). The dispatcher forms the class name via `ucfirst(strtolower($controller)) . 'Controller'`, and PHP class names cannot contain hyphens. Multi-word concepts should be joined: `/privatenote/index` → `PrivatenoteController::indexAction()`, not `/private-note/index`. Action segments follow the same rule.

For the full template, CSS, JavaScript, and auto-loading rules, see `docs/frontend/assets.md`.

## Handle a Form POST

A server-rendered page often needs to accept a form submission. NeNe does not provide a separate `actionPostAction()` convention for HTML form posts — the dispatcher resolves `POST /xxx/index` like this:

1. If `indexPostRest` exists on the controller, the request is treated as a REST call (JSON in / JSON out, CSRF enforced).
2. Otherwise, the request falls through to `indexAction`, regardless of HTTP method.

For a normal HTML form, do not define `indexPostRest`. Let the dispatcher hand POST to `indexAction()`, and branch on `$this->method` inside the controller. Use `$this->request->getPost($key)` to read form fields, and `$this->location($uri)` to redirect after a successful write (the post/redirect/get pattern).

Example goal:

```text
GET  /note/index   → list view
POST /note/index   → create a note, redirect to detail
GET  /note/new     → form view
```

Controller:

```php
<?php

declare(strict_types=1);

namespace Nene\Controller;

use Nene\Database;
use Nene\Xion\ControllerBase;

class NoteController extends ControllerBase
{
    protected function preAction(): void
    {
        $this->SESSION_CHECK = false; // public sandbox page
    }

    public function indexAction(): void
    {
        if ($this->method === 'POST') {
            $this->handleCreate();
            return;
        }
        $mapper = new Database\NoteMapper();
        $this->setTitle('All notes');
        $this->VIEW->setValues('t_notes', $mapper->findRows());
    }

    public function newAction(): void
    {
        $this->setTitle('New note');
        $this->VIEW->setString('t_error', '');
    }

    private function handleCreate(): void
    {
        $title = trim((string)($this->request->getPost('title') ?? ''));
        $body  = trim((string)($this->request->getPost('body')  ?? ''));
        if ($title === '' || $body === '') {
            // Re-render the form template with an error message. The
            // auto-selected template for indexAction is note/index.tpl;
            // setTemplate() switches to note/new.tpl for the re-render.
            $this->VIEW
                ->setString('t_error', 'Title and body are both required.')
                ->setString('t_form_title', $title)
                ->setString('t_form_body', $body)
                ->setTemplate('note/new.tpl');
            return;
        }
        $id = (new Database\NoteMapper())->create($title, $body);
        $this->location('/note/item/id_' . $id);
    }
}
```

Form template (`view/source/note/new.tpl`):

```smarty
{extends file='layout/app.tpl'}
{block name='content'}
                <form method="post" action="{$t_root}note/index">
                    {if strlen($t_error) > 0}<p class="error">{$t_error}</p>{/if}
                    <label>Title <input name="title" value="{$t_form_title|default:''}"></label>
                    <label>Body <textarea name="body">{$t_form_body|default:''}</textarea></label>
                    <button type="submit">Save</button>
                </form>
{/block}
```

Two cautions worth knowing up front:

- `actionAction()` is invoked for **any** HTTP method when no method-specific REST handler exists. If you write a `createAction()` reachable at `GET /note/create`, the action runs on GET too — guard with `$this->method !== 'POST'` before performing any side effect, or use the `indexAction` + internal dispatch shape shown above so write logic lives next to a 'POST' branch.
- CSRF checking is only enforced by the framework for REST mode (`indexPostRest`, etc.) when the user is logged in. HTML actions reached as `actionAction` skip the framework's CSRF gate by design — for authenticated state-changing forms see "Protect an Authenticated Form" below.

## Protect an Authenticated Form

When a server-rendered form submits state-changing data behind a login (creating a note, updating an account, posting a comment), you need an explicit CSRF check. The framework provides two helpers on `ControllerBase` so the controller side is one line each:

| Helper | Use in | What it does |
| --- | --- | --- |
| `$this->csrfToken()` | controller (GET form action) | returns the session's CSRF token to embed in a hidden field |
| `$this->requireCsrfFromPost()` | controller (POST handler) | reads `csrf_token` from `$_POST`, verifies it, and on failure terminates the dispatch with a 403 — REST callers get the `CSRF-TOKEN-INVALID` JSON envelope, HTML callers get the `csrf.html` page |

Skeleton:

```php
class PrivateNoteController extends ControllerBase
{
    public function newAction(): void
    {
        $this->setTitle('New note');
        $this->VIEW->setString('t_csrf_token', $this->csrfToken());
    }

    public function indexAction(): void
    {
        if ($this->method === 'POST') {
            $this->requireCsrfFromPost();
            // ... do the protected write, then redirect ...
            return;
        }
        // ... GET: render the list, including a fresh csrf_token for any inline form ...
        $this->VIEW->setString('t_csrf_token', $this->csrfToken());
    }
}
```

Form template emits the hidden field:

```smarty
<form method="post" action="{$t_root}privatenote/index">
    <input type="hidden" name="csrf_token" value="{$t_csrf_token}">
    <label>Title <input name="title"></label>
    <label>Body <textarea name="body"></textarea></label>
    <button type="submit">Save</button>
</form>
```

The default field name is `csrf_token`. To use a different name, pass it to both sides: `$this->requireCsrfFromPost('my_token')` and `<input type="hidden" name="my_token" value="...">`.

Three notes:

- The session token comes from `AuthSession::csrfToken()`. It is created at login and remains stable for the lifetime of the session, so the same `csrfToken()` value works across all forms in the same login. After logout (or session expiry) the token is regenerated on the next login and old hidden fields stop validating — `requireCsrfFromPost()` surfaces that as a `403` automatically.
- `requireCsrfFromPost()` is the recommended shape. There is also a low-level `verifyCsrfFromPost(): bool` that returns the verification result without terminating; reach for it only when the handler needs to recover (for example, re-render the form with field-level errors) instead of returning a flat 403 page.
- The helpers do not change REST behavior. REST handlers (`indexPostRest` etc.) still go through the automatic framework gate that validates the `X-CSRF-Token` header.

## Add a REST Endpoint

Use method-specific REST handlers for JSON endpoints. Avoid new `{action}Rest()` handlers unless a compatibility reason is documented, because they accept every HTTP method through the legacy fallback.

Example goal:

```text
GET /article/index
POST /article/index
GET /article/item/id_1
```

Controller shape:

```php
<?php

declare(strict_types=1);

namespace Nene\Controller;

use Nene\Database as Database;
use Nene\Xion\ControllerBase;
use Nene\Xion\TransactionManager;

class ArticleController extends ControllerBase
{
    public function indexGetRest(): array
    {
        $mapper = new Database\ArticleMapper();

        return $this->API_RESPONSE->success([
            'articles' => $mapper->findPublishedRows(),
        ]);
    }

    public function indexPostRest(): array
    {
        $title = trim((string)($this->REQUEST_JSON['title'] ?? ''));
        if ($title === '') {
            return $this->API_RESPONSE->failure('ARTICLE-TITLE-REQUIRED');
        }

        $mapper = new Database\ArticleMapper();
        $transaction = new TransactionManager();

        return $this->API_RESPONSE->success([
            'article' => $transaction->run(function () use ($mapper, $title): array {
                return $mapper->create($title);
            }),
        ]);
    }

    public function itemGetRest(): array
    {
        $id = $this->request->getParam('id');
        if ($id === null || !ctype_digit((string)$id)) {
            return $this->API_RESPONSE->failure('ARTICLE-ID-REQUIRED');
        }

        $mapper = new Database\ArticleMapper();
        $article = $mapper->findRowById((int)$id);
        if ($article === null) {
            return $this->API_RESPONSE->failure('ARTICLE-NOT-FOUND');
        }

        return $this->API_RESPONSE->success([
            'article' => $article,
        ]);
    }
}
```

Routing examples:

```text
GET  /article/index     -> ArticleController::indexGetRest()
POST /article/index     -> ArticleController::indexPostRest()
GET  /article/item/id_1 -> ArticleController::itemGetRest()
```

State-changing REST requests such as `POST`, `PUT`, `PATCH`, and `DELETE` require a valid login session and `X-CSRF-Token` when the user is logged in. `/session/login` returns the token as `Data.csrfToken`.

### Normalize the row before returning

Mappers return raw `PDOStatement::fetch(PDO::FETCH_ASSOC)` rows. Every value is a `string` (or `null`) — PDO does not type-cast columns by default. Returning a raw row to JSON ships `"id": "1"`, `"is_completed": "0"`, and other loose types.

Define a small per-controller `normalizeRow()` helper that casts each column to its intended PHP type, and use it on every row the controller returns:

```php
class ArticleController extends ControllerBase
{
    public function itemGetRest(): array
    {
        // ... resolve $id, fetch the row ...
        return $this->API_RESPONSE->success([
            'article' => $this->normalizeRow($article),
        ]);
    }

    public function indexGetRest(): array
    {
        $mapper = new Database\ArticleMapper();
        return $this->API_RESPONSE->success([
            'articles' => array_map([$this, 'normalizeRow'], $mapper->findPublishedRows()),
        ]);
    }

    private function normalizeRow(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'title' => (string)$row['title'],
            'is_published' => (bool)$row['is_published'],
            'created_at' => (string)$row['created_at'],
        ];
    }
}
```

The helper is intentionally per-entity — each table has its own column list and cast intent. `TodoController::normalizeRow()` in the bundled sample app is the canonical example (`is_completed` cast to `bool`, IDs to `int`).

Two reasons to do this even on a one-off endpoint:

- JSON consumers (browsers, JS clients, the contract test) rely on declared types. `"is_published": "0"` evaluates truthy in JavaScript.
- The OpenAPI schema you author (`type: integer`, `type: boolean`) does not reshape the response — it only describes the intended shape. The contract test asserts the wire data conforms.

## Keep Controllers Thin

Small read-only or single-write endpoints may call a mapper directly from the controller. Move logic into a service/use-case class when a controller method starts to coordinate business decisions, multiple mappers, multiple SQL statements, or a transaction.

Use the existing `Nene\Model\` namespace under `class/model/` for application service classes. This keeps the current Composer autoload shape and avoids adding a second dispatcher path.

Controller example:

```php
<?php

declare(strict_types=1);

namespace Nene\Controller;

use Nene\Model\ArticleService;
use Nene\Xion\ControllerBase;

class ArticleController extends ControllerBase
{
    public function indexPostRest(): array
    {
        $title = trim((string)($this->REQUEST_JSON['title'] ?? ''));
        $body = trim((string)($this->REQUEST_JSON['body'] ?? ''));

        $service = new ArticleService();
        $result = $service->createArticle($title, $body);
        if (!$result['ok']) {
            return $this->API_RESPONSE->failure($result['errorCode']);
        }

        return $this->API_RESPONSE->success([
            'article' => $result['article'],
        ]);
    }
}
```

Service example:

```php
<?php

declare(strict_types=1);

namespace Nene\Model;

use Nene\Database as Database;
use Nene\Xion\TransactionManager;

class ArticleService
{
    public function createArticle(string $title, string $body): array
    {
        if ($title === '') {
            return ['ok' => false, 'errorCode' => 'ARTICLE-TITLE-REQUIRED'];
        }

        $mapper = new Database\ArticleMapper();
        $transaction = new TransactionManager();

        return [
            'ok' => true,
            'article' => $transaction->run(function () use ($mapper, $title, $body): array {
                return $mapper->create($title, $body);
            }),
        ];
    }
}
```

The service returns plain application data. The controller decides how to turn that result into a REST response. Mappers still own SQL.

## Add Database Transactions

Use `Nene\Xion\TransactionManager` as the standard transaction boundary. This is the canonical pattern for humans and AI agents when one logical operation needs multiple SQL statements, multiple writes, or multiple mappers.

Do not start a transaction inside `DataMapperBase::execute()`. That method is a single-statement execution boundary. Transactions belong around the use case that needs all writes to succeed or fail together.

Single mapper example:

```php
$mapper = new Database\ArticleMapper();
$transaction = new TransactionManager();

$article = $transaction->run(function () use ($mapper, $title, $body): array {
    return $mapper->create($title, $body);
});
```

Multiple mapper example:

```php
$articleMapper = new Database\ArticleMapper();
$auditMapper = new Database\AuditLogMapper();
$transaction = new TransactionManager();

$article = $transaction->run(function () use ($articleMapper, $auditMapper, $title, $body): array {
    $article = $articleMapper->create($title, $body);
    $auditMapper->record('article.created', (int)$article['id']);

    return $article;
});
```

`TransactionManager::run()` commits when the callback returns and rolls back when the callback throws. If another transaction is already active, the outer boundary remains responsible for the final commit or rollback.

## Add Error Codes

Controllers should reference stable error codes. Messages and HTTP status values live in `config/error_codes.php`.

```php
return [
    'ARTICLE-ID-REQUIRED' => [
        'message' => 'Article id is required.',
        'httpStatus' => 400,
    ],
    'ARTICLE-NOT-FOUND' => [
        'message' => 'Article was not found.',
        'httpStatus' => 404,
    ],
    'ARTICLE-TITLE-REQUIRED' => [
        'message' => 'Article title is required.',
        'httpStatus' => 400,
    ],
];
```

`ApiResponse::failure()` sets the HTTP status from this catalog and returns the shared failure payload:

```json
{
  "status": "failure",
  "errorCode": "ARTICLE-NOT-FOUND",
  "errorMessage": "Article was not found."
}
```

The public JSON envelope wraps that data under `Data`.

> **Also update `docs/development/error-codes.md`.** A unit test (`ErrorCodeTest::testEveryRuntimeCodeAppearsInDocsMarkdownTable`) verifies that every entry in `config/error_codes.php` appears in the markdown catalog table. Adding to the PHP file without updating the markdown causes unit tests to fail. Add a row for each new code in the table in `docs/development/error-codes.md`.

## Add Database Code

Use a data model for schema metadata and validation, and a mapper for SQL.

Model example:

```php
<?php

declare(strict_types=1);

namespace Nene\Database;

use Nene\Xion\DataModelBase;

class Article extends DataModelBase
{
    protected static $schema = [
        'id'         => parent::INTEGER,
        'created_at' => parent::DATETIME,
        'updated_at' => parent::DATETIME,
        'title'      => parent::STRING,
        'body'       => parent::STRING,
        'is_deleted' => parent::BOOLEAN,
    ];

    protected static $validation = [
        'title'      => ['required' => true, 'maxlength' => 255],
        'body'       => ['required' => true],
        'is_deleted' => ['required' => true, 'bool' => true],
    ];
}
```

Mapper example:

```php
<?php

declare(strict_types=1);

namespace Nene\Database;

use Nene\Xion\DataMapperBase;
use PDO;

class ArticleMapper extends DataMapperBase
{
    protected const MODEL_CLASS = 'Nene\Database\Article';
    protected const TARGET_TABLE = 'articles';
    protected const KEY_SID = 'id';

    public function findPublishedRows(): array
    {
        $stmt = $this->DB->prepare('
            SELECT id, title, body, created_at, updated_at
            FROM ' . static::TARGET_TABLE . '
            WHERE is_deleted = 0
            ORDER BY id DESC
        ');

        return $this->execute($stmt)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findRowById(int $id): ?array
    {
        $stmt = $this->DB->prepare('
            SELECT id, title, body, created_at, updated_at
            FROM ' . static::TARGET_TABLE . '
            WHERE id = :id
            AND is_deleted = 0
            LIMIT 1
        ');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        $row = $this->execute($stmt)->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function create(string $title, string $body = ''): array
    {
        $stmt = $this->DB->prepare('
            INSERT INTO ' . static::TARGET_TABLE . ' (
                title,
                body,
                is_deleted
            ) VALUES (
                :title,
                :body,
                0
            )
        ');
        $stmt->bindValue(':title', $title, PDO::PARAM_STR);
        $stmt->bindValue(':body', $body, PDO::PARAM_STR);
        $this->execute($stmt);

        $row = $this->findRowById((int)$this->DB->lastInsertId());
        if ($row === null) {
            throw new \RuntimeException('Created article row could not be loaded.');
        }

        return $row;
    }
}
```

When you add a new table, keep MySQL and SQLite setup aligned:

```text
docker/mysql/init/001_schema.sql
cli/initSQLite.php
```

### Reserved method names in DataMapperBase

`DataMapperBase` already declares `insert()`, `update()`, `delete()`, `find()`, `findALL()`, `countById()`, `countAll()` as public methods. Overriding any of these with an incompatible signature causes a PHP fatal error.

Name your own mapper methods to avoid collisions. For soft-delete (setting `is_deleted = 1`) the conventional choice is `softDelete(int $id): bool`.

### Column nullability

To allow a column to store `NULL`, add `'nullable' => true` to its definition in `SchemaDefinition::tables()`:

```php
'note' => ['type' => 'text', 'nullable' => true],
'parent_id' => ['type' => 'bigint', 'nullable' => true],
```

Without `nullable`, every column compiles to `NOT NULL`. The `nullable` option applies to `text`, `varchar:*`, and `bigint` columns. `pk-bigint`, `bool`, and `datetime-*` columns are always `NOT NULL` and ignore the option.

## Add Authentication Requirements

By default, controllers require a valid session. Set `$this->SESSION_CHECK = false` in `preAction()` only for public pages or public endpoints.

Authentication-related statuses:

- `SESSION-CLOSED`: `401`, no valid login session.
- `LOGIN-FAILED`: `401`, submitted credentials were rejected.
- `CSRF-TOKEN-INVALID`: `403`, session exists but the CSRF token is missing or invalid.

For state-changing REST endpoints:

1. Login with `POST /session/login`.
2. Read `Data.csrfToken`.
3. Send `X-CSRF-Token` with `POST`, `PUT`, `PATCH`, or `DELETE`.

External clients (curl, fetch, custom SDK) also need to manage the `PHPSESSID` cookie returned by login. [`docs/api/reference-client.md`](../api/reference-client.md) covers the full mechanics with runnable examples.

### Add an HTML Login Form

The bundled `SessionController` is REST-only — it handles `POST /session/login` as JSON in / JSON out. For a server-rendered application with a user-facing login page, create your own controller with HTML actions and reuse the framework's `AuthSession` directly. The `login()` accepts the row returned by `UserMapper::findByCredentials($userId, $userPass)`, so the controller stays short:

```php
<?php

declare(strict_types=1);

namespace Nene\Controller;

use Nene\Database;
use Nene\Xion\ControllerBase;

class AuthController extends ControllerBase
{
    protected function preAction(): void
    {
        $this->SESSION_CHECK = false; // the login page must be reachable while unauthenticated
    }

    public function loginAction(): void
    {
        if ($this->method === 'POST') {
            $this->handleLoginPost();
            return;
        }
        $this->setTitle('Sign in');
        $this->VIEW
            ->setString('t_error', '')
            ->setString('t_form_user_id', '');
    }

    public function logoutAction(): void
    {
        if ($this->method !== 'POST') {
            // Defense: a side-effect action should never run on GET.
            $this->location('/auth/login');
            return;
        }
        $this->requireCsrfFromPost();
        $this->AUTH_SESSION->logout(true);
        $this->location('/auth/login');
    }

    private function handleLoginPost(): void
    {
        $userId = trim((string)($this->request->getPost('user_id') ?? ''));
        $userPass = (string)($this->request->getPost('user_pass') ?? '');
        if ($userId === '' || $userPass === '') {
            $this->renderLoginError($userId, 'Enter both user id and password.');
            return;
        }
        $user = (new Database\UserMapper())->findByCredentials($userId, $userPass);
        if ($user === null) {
            $this->renderLoginError($userId, 'Wrong user id or password.');
            return;
        }
        $this->AUTH_SESSION->login($user);
        $this->location('/dashboard');
    }

    private function renderLoginError(string $userId, string $error): void
    {
        $this->setTitle('Sign in');
        $this->VIEW
            ->setString('t_error', $error)
            ->setString('t_form_user_id', $userId)
            ->setTemplate('auth/login.tpl');
    }
}
```

A few framework boundaries are worth pointing out:

- `$this->SESSION_CHECK = false` in `preAction()` keeps the login page itself unauthenticated. Without it, `sessionCheck()` would redirect anonymous visitors away from the login form they need to fill in.
- The login handler does **not** verify a CSRF token — the user has no session yet, so there is nothing to bind a token to. Logout and any subsequent protected forms do require CSRF; see "Protect an Authenticated Form" above.
- `$this->AUTH_SESSION->login($user)` calls `session_regenerate_id(true)` internally. Browsers handle the two `Set-Cookie: PHPSESSID=...` headers correctly; hand-written clients should follow [`docs/api/reference-client.md`](../api/reference-client.md).
- After login, redirect to a protected page (`/dashboard` here) via `$this->location()`. The framework's `sessionCheck()` will redirect unauthenticated visitors to `LOGOUT_URI` (set `NENE_LOGOUT_URI=/auth/login` so they land on this form, or override `unauthorizedRedirect()` per controller).

Matching login form template (`view/source/auth/login.tpl`):

```smarty
{extends file='layout/app.tpl'}
{block name='content'}
                <form method="post" action="{$t_root}auth/login">
                    {if strlen($t_error) > 0}<p class="error">{$t_error}</p>{/if}
                    <label>User ID <input name="user_id" value="{$t_form_user_id|default:''}" autocomplete="username" required></label>
                    <label>Password <input type="password" name="user_pass" autocomplete="current-password" required></label>
                    <button type="submit">Sign in</button>
                </form>
{/block}
```

A logout button on a protected page is just a one-line form that posts the CSRF token to `logoutAction()`:

```smarty
<form method="post" action="{$t_root}auth/logout" style="display:inline">
    <input type="hidden" name="csrf_token" value="{$t_csrf_token}">
    <button type="submit">Sign out</button>
</form>
```

## Update OpenAPI

Every public REST endpoint should be described in:

```text
docs/api/openapi.yaml
```

For a new endpoint, add:

- Path and HTTP method.
- Request body schema, if any.
- Success response schema.
- Failure responses such as `400`, `401`, `403`, `404`, and `405`.
- Any security requirements, including `sessionCookie` and `csrfToken`.

Swagger UI is available locally at:

```text
http://localhost:8080/api-docs/
```

### File upload (multipart) operations

For endpoints that accept a `multipart/form-data` request body, use:

```yaml
/attachment/index:
  post:
    tags: [Attachment]
    summary: Upload an attachment for the signed-in user
    operationId: createAttachment
    security:
      - sessionCookie: []
        csrfToken: []
    requestBody:
      required: true
      content:
        multipart/form-data:
          schema:
            type: object
            required: [file]
            properties:
              file:
                type: string
                format: binary
    responses:
      "200":
        description: Upload accepted.
        # ... your success envelope ...
      "400":
        description: Upload file is missing (`UPLOAD-FILE-REQUIRED`).
        content:
          application/json:
            schema:
              $ref: "#/components/schemas/ApiFailureEnvelope"
            examples:
              uploadRequired:
                value:
                  Result: true
                  Data:
                    status: failure
                    errorCode: UPLOAD-FILE-REQUIRED
                    errorMessage: Upload file is required.
      "401":
        $ref: "#/components/responses/SessionClosed"
      "403":
        $ref: "#/components/responses/CsrfTokenInvalid"
      "413":
        description: Upload exceeds size limit (`UPLOAD-TOO-LARGE`).
      "415":
        description: Upload mime type is not allowed (`UPLOAD-MIME-REJECTED`).
```

The runtime contract test (`OpenApiRuntimeContractTest`) only reads JSON examples; it probes multipart operations with an **empty body**. Make sure the documented `responses:` list includes whatever status your controller returns for the "missing file" case — for the framework's `UploadedFile::validate()` helper that's `400` `UPLOAD-FILE-REQUIRED`. If the operation cannot tolerate the empty-body probe (destructive write etc.), add `x-nene-runtime-probe: skip`.

## Send an email

`Nene\Xion\Mailer` (ADR-0006) wraps Symfony Mailer. Send a message from inside any controller:

```php
use Nene\Xion\MailMessage;
use Nene\Xion\Mailer;

class AuthController extends ControllerBase
{
    public function passwordResetPostRest(): array
    {
        // ... resolve $email, generate $token ...

        Mailer::getInstance()->send(new MailMessage(
            to: $email,
            subject: 'Reset your password',
            body: "Open the link to reset:\n\nhttps://example.com/auth/reset?token={$token}\n",
        ));

        return $this->API_RESPONSE->success(['queued' => true]);
    }
}
```

Pass `contentType: 'text/html'` for HTML mail and `from:` to override the default sender. The transport reads from `NENE_MAIL_DSN`; the dev stack catches everything in mailpit at `http://localhost:8025/` so you never accidentally email a real user.

See `docs/development/email-sending.md` for the full guide (env matrix, production SMTP, test injection, what is intentionally out of scope).

## Add Tests

Add the smallest useful test for the behavior.

Use unit tests for pure or boundary-level code:

```text
tests/Unit/
```

Use HTTP runtime tests for real routing, sessions, cookies, REST payloads, and OpenAPI status coverage:

```text
tests/Http/
```

Good HTTP test targets:

- Successful login and CRUD flow.
- Authentication failure returns `401`.
- Validation failure returns a catalog error code.
- Missing records return `404`.
- Unsupported methods return `405` and `Allow`.
- OpenAPI documents observed runtime statuses.

### Per-entity CRUD test pattern

`tests/Http/TodoTest.php` is the canonical per-entity CRUD test example. Study it before writing your first HTTP test. The key conventions:

- **Prefix test data** with `self::TEST_TODO_PREFIX` (inherited from `HttpRuntimeTestCase`). The base `setUp()` deletes any leftover rows with that prefix before each test, preventing cross-test contamination.
- **Track created rows** by calling `$this->createTodo()` (or your own helper that appends to `$this->cleanupTodoIds`). The base `tearDown()` deletes all tracked rows even when an assertion fails mid-test.
- **Get an authenticated client** with `$this->loginAsAdmin()`. The `HttpClient` automatically stores the session cookie and CSRF token after the login response, so subsequent `POST`/`PUT`/`DELETE` calls send `X-CSRF-Token` without any extra setup.
- **Test unauthenticated access** by calling `$this->newClient()` — it returns a fresh client with no session, simulating an anonymous browser.
- One behaviour per test method; keep assertions tight (status code + error code is enough for error cases).

## Implementation Checklist

Before opening a PR:

- Create or confirm the GitHub Issue.
- Add or update controller methods.
- Add service/use-case code when business logic would make a controller method hard to read.
- Add templates/assets for HTML pages.
- Add mapper/model/schema changes for database-backed features.
- Add error codes to `config/error_codes.php`.
- Update `docs/api/openapi.yaml` for public REST endpoints.
- Add focused unit or HTTP runtime tests.
- Review `docs/ai/self-review/` before opening the PR.
- Run `composer test`.
- Run `composer analyze`.
- Run `NENE_HTTP_BASE_URL=http://localhost:8080 composer test:http` when HTTP behavior changes.

Keep each PR focused. A page, an API endpoint, and a schema change can be together when they are one feature, but unrelated cleanup should be separate.
