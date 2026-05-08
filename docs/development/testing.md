# Testing

NeNe is a legacy framework with tightly coupled areas, so the test strategy starts small.

## First Target

Begin with pure functions and small utilities that can be tested without booting the framework.

Good early targets:

- `Nene\Func\Text`
- Small value transformation helpers
- Extracted routing parser functions, after they exist

Avoid as first targets:

- Functions that call `header()`
- Functions that emit HTTP output directly instead of returning or throwing a response boundary value
- Code that requires global constants, `$_SERVER`, sessions, database connections, or Smarty setup

## Run Tests

```sh
composer test
```

Or directly:

```sh
vendor/bin/phpunit
```

`composer test` runs the lightweight unit test suite only.

For CI-style verification that also confirms HTTP tests are either runnable or safely skipped:

```sh
composer check
```

## Static Analysis

Phan is configured under `.phan/config.php` and can be run through Composer:

```sh
composer analyze
```

The initial `.phan/baseline.php` records issues that already exist in the legacy codebase. New work should avoid adding fresh Phan issues; reduce the baseline gradually in focused PRs rather than mixing broad static-analysis cleanup into unrelated changes.

### ReactPHP Dependency Note

Packages under `vendor/react/*` are ReactPHP packages pulled in through development tooling such as Phan. They are unrelated to browser React and do not mean the NeNe runtime depends on asynchronous PHP event loops.

Runtime dependencies should be checked in `composer.json` under `require`; development-only tools belong under `require-dev`.

## Docker

Tests can also run in the Docker container:

```sh
docker compose run --rm app sh -lc "composer install --no-interaction --prefer-dist && composer test"
```

## HTTP Runtime Smoke Tests

HTTP runtime tests exercise the Docker-served application through real HTTP requests. They cover the top page, explicit URL routing, Swagger UI, session login/logout, TODO CRUD, REST method handling, and the JSON-only REST response policy.

Start the Docker environment first:

```sh
docker compose up --build -d
```

Then run:

```sh
NENE_HTTP_BASE_URL=http://localhost:8080 composer test:http
```

If `NENE_HTTP_BASE_URL` is not configured, or the target server is unreachable, the HTTP test suite is skipped. This keeps normal unit testing fast and independent from Docker.

For a full local runtime check:

```sh
docker compose up --build -d
NENE_HTTP_BASE_URL=http://localhost:8080 composer check
```

HTTP tests use a test title prefix and clean up matching TODO rows before each runtime test. Tests that create TODOs also register them for cleanup during teardown, so failed tests should not leave long-lived sample data behind.

### Error Exposure Check

`HttpErrorExposureTest` is an optional HTTP runtime test for public error responses. It runs only when `NENE_HTTP_ERROR_BASE_URL` points to a deliberately broken production-like app, for example one started with `NENE_APP_DEBUG=0` and invalid database settings.

```sh
NENE_HTTP_ERROR_BASE_URL=http://localhost:8081 vendor/bin/phpunit tests/Http/HttpErrorExposureTest.php
```

The test confirms that database connection failures return a generic `Internal Server Error` body instead of leaking `SQLSTATE` or connection details.

## OpenAPI Contract Test Scope

`OpenApiRuntimeContractTest` reads `docs/api/openapi.yaml` with `symfony/yaml` and verifies a small runtime contract: documented REST operations must exist, and observed runtime HTTP statuses must be listed in OpenAPI.

This is not a full OpenAPI validator. When OpenAPI grows to require response body schema validation, evaluate an OpenAPI/JSON Schema validator separately instead of expanding this smoke test into a custom validator.

## Strategy for Coupled Code

For coupled framework code, prefer this order:

1. Add a Docker HTTP smoke test for current behavior.
2. Fix environment-dependent warnings and fatal errors.
3. Extract small pure logic from the coupled class.
4. Add PHPUnit tests for the extracted logic.
5. Keep the legacy public behavior unchanged unless an Issue explicitly allows a breaking change.

Dispatcher and REST behavior should be improved together with Issue #10.
