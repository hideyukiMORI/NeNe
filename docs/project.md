# NeNe Project Overview

NeNe is a legacy, simple, lightweight PHP web application framework.

The project should remain small and understandable. New work should improve maintainability, security, and standards compatibility without turning NeNe into a large full-stack framework.

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
- JSON/API method: `{action}Rest`

If both `{action}Action` and `{action}Rest` exist, the route is treated as invalid. If neither exists, NeNe returns 404.

Examples:

- `/` resolves to `IndexController::indexAction()` or `IndexController::indexRest()`.
- `/session/login` resolves to `SessionController::loginAction()` or `SessionController::loginRest()`.

## Direction

NeNe should evolve as:

- A legacy-compatible but modernizing PHP framework.
- PSR-aware, especially for autoloading, coding style, HTTP/API boundaries, and logging.
- Composer-based, with packages kept on current stable versions where practical.
- Security-conscious by default.
- API-first where new external contracts are introduced, with OpenAPI used to describe public HTTP APIs.

## Non-Goals

- Do not add large framework dependencies without an Issue and ADR.
- Do not introduce a new routing architecture casually.
- Do not hide behavior behind heavy magic or implicit global state beyond what already exists.
- Do not mix broad modernization with unrelated feature changes.
