# Error Codes

Catalog of NeNe REST API error codes. The runtime source of truth is `config/error_codes.php`; this file restates the catalog in a form OpenAPI consumers can read alongside `docs/api/openapi.yaml`.

For the envelope shape rationale, see ADR 0003.

## Envelope

Every failure response uses the same envelope, documented in OpenAPI as `ApiFailureEnvelope`:

```json
{
  "Result": true,
  "Data": {
    "status": "failure",
    "errorCode": "TODO-NOT-FOUND",
    "errorMessage": "TODO item was not found."
  }
}
```

`Result` stays `true` because the response was produced by the controller (a 5xx wraps a different shape). `Data.status` is always `failure` for the codes below. The HTTP status is set by the controller based on `httpStatus` in the runtime catalog.

## Catalog

| Error code | HTTP status | Message | Notes |
| --- | --- | --- | --- |
| `SESSION-CLOSED` | 401 | Session timeout. Please log in again. | Returned when an authenticated endpoint is called without a valid `PHPSESSID` cookie. |
| `LOGIN-FAILED` | 401 | Wrong user ID or user PASS | Returned by `POST /session/login` for rejected credentials. |
| `CSRF-TOKEN-INVALID` | 403 | Invalid CSRF token. | Returned when a state-changing request from a logged-in client is missing or has a wrong `X-CSRF-Token` header. |
| `METHOD-NOT-ALLOWED` | 405 | The HTTP method is not allowed for this endpoint. | Returned with the `Allow` response header listing valid methods. |
| `ROUTE-CONFLICT` | 500 | Route configuration conflict. | Internal — surfaces when controller dispatch is ambiguous. |
| `TODO-ID-REQUIRED` | 400 | TODO id is required. | `/todo/item/id_X` with missing or non-numeric `id`. |
| `TODO-NOT-FOUND` | 404 | TODO item was not found. | `/todo/item/id_X` where no row matches the signed-in user. |
| `TODO-TITLE-REQUIRED` | 400 | TODO title is required. | `POST /todo/index` or `PUT /todo/item/id_X` with empty `title`. |

## Adding a new error code

1. Add the entry to `config/error_codes.php` with `message` and `httpStatus`.
2. Add a row to the table above. Match the order of the runtime file so the two are easy to diff.
3. If the code is referenced from a new OpenAPI endpoint, the endpoint's failure response references the shared `ApiFailureEnvelope` — no per-code schema is added. The endpoint MAY include an `example` showing the specific `errorCode` value it produces.
4. The contract test (`tests/Http/OpenApiRuntimeContractTest`) automatically discovers new endpoints and asserts that observed statuses appear in the documented status list. No test changes are needed for a new error code on an existing endpoint.

## Related

- `config/error_codes.php` — runtime catalog.
- `docs/api/openapi.yaml` — OpenAPI contract; references `ApiFailureEnvelope` for every failure response.
- `docs/api/reference-client.md` — failure-mode table for external consumers.
- ADR 0003 — rationale for the generic envelope shape.
