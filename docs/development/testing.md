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
- Functions that `echo` and `exit()`
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

## Docker

Tests can also run in the Docker container:

```sh
docker compose run --rm app sh -lc "composer install --no-interaction --prefer-dist && composer test"
```

## HTTP Runtime Smoke Tests

HTTP runtime tests exercise the Docker-served application through real HTTP requests. They cover the top page, Swagger UI, session login/logout, TODO CRUD, and REST method handling.

Start the Docker environment first:

```sh
docker compose up --build -d
```

Then run:

```sh
NENE_HTTP_BASE_URL=http://localhost:8080 composer test:http
```

If `NENE_HTTP_BASE_URL` is not configured, or the target server is unreachable, the HTTP test suite is skipped. This keeps normal unit testing fast and independent from Docker.

## Strategy for Coupled Code

For coupled framework code, prefer this order:

1. Add a Docker HTTP smoke test for current behavior.
2. Fix environment-dependent warnings and fatal errors.
3. Extract small pure logic from the coupled class.
4. Add PHPUnit tests for the extracted logic.
5. Keep the legacy public behavior unchanged unless an Issue explicitly allows a breaking change.

Dispatcher and REST behavior should be improved together with Issue #10.
