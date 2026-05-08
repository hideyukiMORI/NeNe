# API Documentation

NeNe should use OpenAPI for public HTTP API contracts.

## Policy

- New public REST endpoints should be documented with OpenAPI.
- Request parameters, request bodies, responses, and error formats should be explicit.
- OpenAPI files should live under `docs/api/` unless an ADR changes the location.
- API behavior should not rely only on controller implementation details.

## Current State

The starter OpenAPI contract is defined at:

```text
docs/api/openapi.yaml
```

Local Swagger UI is available at:

```text
http://localhost:8080/api-docs/
```

`docs/api/openapi.yaml` is the source of truth. The Swagger UI page serves that file through `htdocs/api-docs/openapi.php` so the contract is not duplicated under the document root.

## Contract Test Parser Policy

The current runtime contract test uses a small line-based reader for `openapi.yaml`. That is acceptable while the contract is small and the test only checks path, method, and documented HTTP status coverage.

Before expanding the test into richer OpenAPI assertions, parse the YAML with a real parser such as `symfony/yaml` in `require-dev`. If the project starts validating response bodies against schemas, choose an OpenAPI/JSON Schema validation library deliberately instead of extending the current regular expressions.

## REST Convention

NeNe currently treats controller methods ending in `Rest` as REST/API handlers.

Example:

```text
/session/login -> SessionController::loginPostRest()
```

OpenAPI paths should describe the URL and the request/response contract, not the internal method name.
