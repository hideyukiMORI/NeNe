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

The runtime contract test parses `openapi.yaml` with `symfony/yaml`. This keeps the test resilient to normal YAML formatting changes while still keeping the assertion scope small.

Before expanding the test into response body validation, choose an OpenAPI/JSON Schema validation library deliberately. Do not turn the runtime smoke test into a custom full OpenAPI validator.

## REST Convention

NeNe currently treats controller methods ending in `Rest` as REST/API handlers.

Example:

```text
/session/login -> SessionController::loginPostRest()
```

OpenAPI paths should describe the URL and the request/response contract, not the internal method name.

## CSRF Protection

Cookie-authenticated state-changing REST requests must send the `X-CSRF-Token` header. `/session/login` returns the token as `Data.csrfToken`; the React sample stores it in memory and sends it with TODO create/update/delete and logout requests.

## Authentication Failure Status

Authentication failures use HTTP `401 Unauthorized`. `LOGIN-FAILED` means submitted credentials were rejected, and `SESSION-CLOSED` means a cookie-authenticated endpoint was called without a valid login session.

## JSONP

JSON is the default REST response format. Legacy JSONP output remains for compatibility, but callbacks must be valid JavaScript identifier paths such as `jsonCallback` or `App.callbacks.done`; invalid callback values fall back to `jsonCallback`.
