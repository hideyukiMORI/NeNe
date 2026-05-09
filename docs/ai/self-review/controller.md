# Controller Self-Review

Use this checklist to keep NeNe controllers thin and predictable.

## Controller Responsibilities

- The controller maps one visible route convention to one method, such as `/article/index` to `ArticleController::indexGetRest()`.
- The controller reads request data from `REQUEST_JSON`, request parameters, or framework helpers.
- The controller performs HTTP-boundary checks such as required parameters and response selection.
- The controller calls a mapper only for simple read/write behavior, or calls a service when the use case has business rules.
- The controller returns through `ApiResponse` for REST endpoints.
- The controller sets template values for server-rendered pages and leaves rendering to `ControllerBase`.

## Move Logic Out When

- A method coordinates multiple mappers.
- A method needs a transaction.
- A method has business decisions that are not about HTTP, sessions, CSRF, or response shape.
- The same logic would be needed by another page, REST endpoint, CLI command, or test.
- The controller method is becoming difficult to read top-to-bottom.

## Avoid

- Do not put SQL in controllers.
- Do not build raw JSON response arrays by hand when `ApiResponse` can express the result.
- Do not start PDO transactions directly in controllers when `TransactionManager` should own the boundary.
- Do not add new `{action}Rest()` methods unless compatibility requires the legacy all-method fallback.
- Do not add framework-core helpers to avoid writing a small service class or mapper method.
