# Building a Service with NeNe

This tutorial shows the common implementation path for a small NeNe service.

Use it as a checklist when adding application behavior:

1. Add a server-rendered page when users need HTML.
2. Add method-specific REST handlers when React or another client needs JSON.
3. Add database mapper/model code when the feature stores data.
4. Add error codes, OpenAPI, and tests at the same time as the endpoint.

NeNe is intentionally small. Prefer explicit controller methods, clear data mappers, and focused tests over hidden framework behavior.

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

Optional page assets are discovered by convention:

```text
htdocs/css/page/about.css
htdocs/js/page/about.js
```

The dispatcher resolves `/page/about` to `PageController::aboutAction()`. `ControllerBase` automatically chooses `view/source/page/about.tpl` when it exists.

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

        return $this->API_RESPONSE->success([
            'article' => $mapper->create($title),
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

## Implementation Checklist

Before opening a PR:

- Create or confirm the GitHub Issue.
- Add or update controller methods.
- Add templates/assets for HTML pages.
- Add mapper/model/schema changes for database-backed features.
- Add error codes to `config/error_codes.php`.
- Update `docs/api/openapi.yaml` for public REST endpoints.
- Add focused unit or HTTP runtime tests.
- Run `composer test`.
- Run `composer analyze`.
- Run `NENE_HTTP_BASE_URL=http://localhost:8080 composer test:http` when HTTP behavior changes.

Keep each PR focused. A page, an API endpoint, and a schema change can be together when they are one feature, but unrelated cleanup should be separate.
