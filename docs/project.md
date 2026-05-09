# NeNe Project Overview

NeNe is a renovated legacy PHP web application framework.

The project started from an older, intentionally small PHP framework style. Its purpose is not to erase that shape, but to make it usable today: PHP 8.4 target, Composer packages, Docker setup, PHPUnit, Phan, PHP CS Fixer, OpenAPI, stronger sessions, CSRF, password hashing, and safer error handling.

NeNe is for developers who are comfortable with older convention-based PHP frameworks and want a codebase they can read from end to end. It keeps the familiar "URL segment -> controller -> action" flow while modernizing the boundaries around it.

NeNe is also a good fit for AI-assisted development because its existing conventions are visible and predictable. That does not mean promising that every generated change is automatically good. It means keeping the framework small, explicit, documented, and testable enough that code written by a human or assisted by an AI agent can be reviewed by a human without first decoding a large amount of framework magic.

The project should remain small and understandable. New work should improve maintainability, security, and standards compatibility without turning NeNe into a large full-stack framework.

For small services, the desired experience is a short path from `git clone` to local verification, then to a simple deployable shape: Docker for local work, traditional Apache/PHP documentation for server install, explicit database setup, OpenAPI where public APIs exist, and focused tests around behavior that matters.

## Renovation Philosophy

NeNe is a renovation project, not a rewrite.

Keep:

- The front controller entry point.
- The `/{controller}/{action}` URL convention.
- Controller method names such as `indexAction()`.
- Simple Smarty-based server rendering.
- Lightweight database mapper classes instead of a heavy ORM.
- A small codebase that one developer can inspect quickly.

Modernize:

- Composer autoloading and package management.
- PHP 8.4-oriented typing and strictness where practical.
- Method-specific REST handlers such as `indexGetRest()` and `indexPostRest()`.
- Centralized API responses and error codes.
- Session lifecycle, CSRF, password hashing, JSON-only REST responses, and public error behavior.
- OpenAPI contracts, Swagger UI, PHPUnit, Phan, PHP CS Fixer, and Docker development.
- AI-readable conventions, docs, and tests that keep human review practical.

Avoid:

- Replacing the framework with a large dependency.
- Adding configurable routing magic that hides the URL convention.
- Introducing an ORM or plugin system before a real project need exists.
- Refactoring legacy conventions only for aesthetic reasons.

## Architecture Policy

Large architecture changes are not the current goal.

NeNe should feel familiar to developers who have used older PHP MVC frameworks. The important design surface is the visible, predictable path from URL to controller method:

```text
/{controller}/{action}
```

That route convention is part of the product philosophy. Improvements should make it safer, clearer, and easier to test without hiding it behind a new router abstraction.

When considering changes, prefer this order:

1. Document the existing convention.
2. Add focused tests around the boundary.
3. Improve safety or typing locally.
4. Create an ADR only if the change affects routing, controller conventions, dependency policy, security posture, or API boundaries.

Do not treat legacy style as a defect by itself. In NeNe, some legacy shape is intentionally preserved so the framework stays small, readable, and approachable for its target users.

## Current Shape

- PHP application framework with a front controller entry point at `htdocs/index.php`.
- Composer autoload maps application namespaces under `class/`.
- `Nene\Xion\Dispatcher` parses URL path segments and dispatches a controller method.
- `Nene\Xion\ControllerBase` coordinates request handling, sessions, templates, JSON responses, and common view values.
- Smarty is used for server-rendered templates.
- Monolog is used for logging.

## Routing Convention

NeNe routes by URL path segments.

Given a URL like:

```text
/{controller}/{action}
```

the dispatcher resolves:

- Controller class: `Nene\Controller\{Controller}Controller`
- HTML action method: `{action}Action`
- JSON/API method: `{action}{HttpMethod}Rest`, such as `indexGetRest` or `loginPostRest`

The legacy `{action}Rest` fallback still exists for compatibility, but new REST endpoints should use method-specific handlers. If both `{action}Action` and the legacy `{action}Rest` exist, the route is treated as invalid. If neither an HTML action nor a matching REST handler exists, NeNe returns 404 or 405 depending on available methods.

Examples:

- `/` resolves to `IndexController::indexAction()`.
- `POST /session/login` resolves to `SessionController::loginPostRest()`.
- `GET /todo/index` resolves to `TodoController::indexGetRest()`.

## Direction

NeNe should evolve as:

- A legacy-compatible but modernizing PHP framework.
- A human-friendly and AI-readable framework where generated or hand-written changes follow the same visible conventions.
- A fast path for building and delivering small, secure services without adopting a large full-stack framework.
- PSR-aware, especially for autoloading, coding style, HTTP/API boundaries, and logging.
- Composer-based, with packages kept on current stable versions where practical.
- Security-conscious by default.
- API-first where new external contracts are introduced, with OpenAPI used to describe public HTTP APIs.

## Non-Goals

- Do not add large framework dependencies without an Issue and ADR.
- Do not introduce a new routing architecture casually.
- Do not hide behavior behind heavy magic or implicit global state beyond what already exists.
- Do not mix broad modernization with unrelated feature changes.
