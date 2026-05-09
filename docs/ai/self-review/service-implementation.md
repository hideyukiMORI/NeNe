# Service Implementation Self-Review

Use this checklist when adding or changing a NeNe feature. It is written for AI agents, but human contributors should be able to use it the same way.

## Scope

- The change is tied to a GitHub Issue.
- The PR changes one feature or one documented cleanup, not unrelated framework work.
- The feature code stays outside `class/xion/` unless the Issue is explicitly about framework core.
- The change does not introduce a new routing system, ORM, plugin layer, or dispatcher scan path.

## Standard Shape

- Controller methods read HTTP input and return `ApiResponse` payloads or set template values.
- Service/use-case code owns business rules when logic is more than simple request/response wiring.
- Mapper classes own SQL and database row conversion.
- Model classes own schema metadata and model validation.
- Error messages and HTTP statuses are added to `config/error_codes.php`.

## API and Security

- New REST endpoints use method-specific handlers such as `indexGetRest()` or `indexPostRest()`.
- State-changing REST endpoints keep the existing session and CSRF behavior.
- Public REST behavior is reflected in `docs/api/openapi.yaml`.
- Input from request JSON, path parameters, query strings, and headers is validated before use.
- Production responses do not expose stack traces, SQL details, secrets, or local paths.

## Tests and Docs

- Add focused unit tests for pure service logic when practical.
- Add HTTP runtime tests when routing, sessions, cookies, JSON shape, OpenAPI status coverage, or browser-visible behavior changes.
- Update tutorials or self-review docs when the implementation creates a new preferred pattern.
- Run `composer test` and `composer analyze`.
- Run `NENE_HTTP_BASE_URL=http://localhost:8080 composer test:http` when HTTP runtime behavior changes.
