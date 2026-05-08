# API Documentation

NeNe should use OpenAPI for public HTTP API contracts.

## Policy

- New public REST endpoints should be documented with OpenAPI.
- Request parameters, request bodies, responses, and error formats should be explicit.
- OpenAPI files should live under `docs/api/` unless an ADR changes the location.
- API behavior should not rely only on controller implementation details.

## Current State

No OpenAPI contract is defined yet.

## Starter Structure

When API documentation begins, prefer:

```text
docs/api/openapi.yaml
docs/api/schemas/
```

## REST Convention

NeNe currently treats controller methods ending in `Rest` as REST/API handlers.

Example:

```text
/session/login -> SessionController::loginRest()
```

OpenAPI paths should describe the URL and the request/response contract, not the internal method name.
