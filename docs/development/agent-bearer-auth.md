# Agent Bearer Authentication

How NeNe accepts an optional `Authorization: Bearer <token>` for stateless agent / MCP clients, alongside the existing browser session-cookie + CSRF flow. Pair with **ADR-0008** (`docs/adr/0008-optional-bearer-for-agent-routes.md`).

Audience: anyone integrating NeNe with an MCP server (e.g. `nene-mcp`), a script, an automation agent, or anything else that cannot maintain a cookie jar. Trial source: FT16 (`docs/field-trials/2026-05-field-trial-16.md`), driven by the cross-repo handoff from nene-mcp Issue #380.

## TL;DR

```bash
# 1. set the env vars on the NeNe container
NENE_AGENT_BEARER_TOKEN=<long random secret>
NENE_AGENT_BEARER_USER=admin   # default

# 2. call NeNe with the token; no cookie, no CSRF
curl -H "Authorization: Bearer <long random secret>" \
     http://nene.example.com/todo/index
```

Browser users continue to log in with `POST /session/login` and use the resulting `PHPSESSID` cookie plus `X-CSRF-Token` header. Both flows coexist; neither blocks the other.

## How it works

1. `htdocs/index.php` calls `Nene\Xion\BearerAuth::resolve()` immediately after `session_start()`.
2. `resolve()` reads the inbound `Authorization` header through a four-layer fallback (see "mod_php quirk" below).
3. If the token matches `NENE_AGENT_BEARER_TOKEN` (via `hash_equals` — constant-time), the helper looks up the user identified by `NENE_AGENT_BEARER_USER` (default `admin`) and calls `AuthSession::bindBearer($userRow)`.
4. From this point in the request, `AUTH_SESSION->isLoggedIn()` returns `true`, `AUTH_SESSION->user()` returns the bearer-bound user, and `AUTH_SESSION->isBearerAuthenticated()` returns `true`.
5. `CsrfProtectionPolicy::requiresToken()` sees the bearer flag and returns `false`, so state-changing requests do not need `X-CSRF-Token`.
6. The next request starts from scratch — Bearer authentication is **stateless** on the server side, the bearer user is **not** stored in `$_SESSION`, and the PHP session cookie is never touched.

When `NENE_AGENT_BEARER_TOKEN` is empty (the default), `resolve()` is a no-op — the cookie+CSRF path runs unchanged.

## Env vars

| Variable | Default | Production-safe value | What it controls |
| --- | --- | --- | --- |
| `NENE_AGENT_BEARER_TOKEN` | empty (feature off) | a long random secret (≥32 bytes of entropy) | The expected Bearer value. Empty disables the feature. Rotate by changing the value. |
| `NENE_AGENT_BEARER_USER` | `admin` | the `user_id` of the user the Bearer should act as | Identity the token maps to. Must exist in the `users` table. |

The integrator side (e.g. nene-mcp) reads its own env (e.g. `NENE_MCP_BEARER_TOKEN`) and forwards the value as `Authorization: Bearer ...`. The two env names are independent — only the **values** must match.

## mod_php Authorization quirk

Apache `mod_php` strips the `Authorization` header from `$_SERVER['HTTP_AUTHORIZATION']` by default (the "security" hardening Apache ships with). `apache_request_headers()` / `getallheaders()` still see it.

`BearerAuth::readAuthorizationHeader()` handles this with a four-layer fallback:

1. `$_SERVER['HTTP_AUTHORIZATION']`
2. `$_SERVER['REDIRECT_HTTP_AUTHORIZATION']` (mod_rewrite path)
3. `apache_request_headers()` (case-insensitive)
4. `getallheaders()` (case-insensitive)

No Apache configuration change is required. The same recipe is useful for any other future framework helper that reads inbound headers — keep this pattern in mind when adding helpers that touch `Authorization`, `X-Api-Key`, or similar.

## In-memory binding, per request

`AuthSession::bindBearer()` stores the bearer-bound user in a private instance field, **not** in `$_SESSION`. This is intentional:

- The PHP session cookie is not generated or rotated for Bearer requests.
- Two concurrent requests with the same Bearer get fresh authenticate-per-request — no shared state.
- The next request from the same client must re-authenticate (re-send the header). This is the correct stateless property.

`isLoggedIn()` / `user()` / `userId()` return the bearer-bound user with precedence over any `$_SESSION` content, so an agent request that happens to carry both a Bearer header and a stale `PHPSESSID` cookie sees the Bearer user (the request's intent).

## CSRF behavior

`CsrfProtectionPolicy::requiresToken()` short-circuits to `false` when `$isBearerAuthenticated` is true. Rationale:

- CSRF protects against cross-site form submission, which is a browser-only attack surface.
- A Bearer token in `Authorization` cannot be auto-attached by a third-party site to a victim's request (browsers do not auto-send `Authorization` headers across origins).
- Forcing CSRF on Bearer requests would require server-side state (cookie jar + token mint), which contradicts the stateless property.

Browser flows are unaffected — `Authorization` is absent on browser POSTs, so `isBearerAuthenticated()` is false and CSRF runs normally.

## OpenAPI

The TODO sample app's operations carry two security alternatives:

```yaml
security:
  - sessionCookie: []
    csrfToken: []
  - bearerAuth: []
```

Either path authenticates the request. New endpoints that should accept agents add `bearerAuth: []` as a sibling alternative. Endpoints that are **browser-only** (e.g. HTML form-rendering routes) keep `sessionCookie + csrfToken` only.

## Security notes

- **Choose a long random token.** Minimum recommendation: 32 bytes of entropy (`openssl rand -hex 32`). The framework does not validate token strength.
- **Do not log the token.** The framework logs the resolved user identity, never the token bytes. Avoid `print_r($_SERVER)` or similar diagnostic dumps in custom controllers.
- **Rotate by changing env.** No graceful overlap window — when the env changes, the old token immediately invalidates. Coordinate with the integrator before flipping the value.
- **Token == admin.** The current design maps one env token to one user. Anyone with the token has that user's full read/write surface. A real multi-integrator deploy needs per-user personal-access-tokens (future ADR).
- **HTTPS in production.** A Bearer token sent over plain HTTP is captured by anyone on the network path. Always deploy NeNe behind an HTTPS terminator.

## Future direction: per-user personal access tokens

ADR-0008's chosen shape (Option C) is a minimum viable Bearer surface. The natural extension (Option B in the ADR's table) is a `personal_access_tokens` table storing hashed token + scope + expiry + audit metadata per token. The helper's public surface (`BearerAuth::resolve()`) does not change — only the lookup step (`hash_equals` against an env value → DB hash comparison + scope check) changes.

A future trial may pick this up when:

- A real deploy needs more than one integrator with distinct audit trails.
- Token rotation needs an overlap window (old + new valid simultaneously).
- Per-route or per-scope authorization is needed beyond "is this the admin?".

## JWT-based Bearer auth: `JwtCodec`

`Nene\Xion\JwtCodec` ships with NeNe. Set `NENE_JWT_SECRET` in `.env` and use:

```php
$jwt = new JwtCodec((string)getenv('NENE_JWT_SECRET'));

// Issue (e.g. in login action)
$token = $jwt->issue(['sub' => $userId, 'email' => $user->email]);

// Verify in preAction (throws 401 on invalid/expired)
protected function preAction(): void
{
    $this->JWT_CLAIMS = (new JwtCodec((string)getenv('NENE_JWT_SECRET')))->require();
}

// Access claims in REST method
$userId = (int)($this->JWT_CLAIMS['sub'] ?? 0);
```

`issue()` automatically sets `iat` and `exp`. Tokens without `exp` are always rejected.

### Security edge cases (FT94 findings from NENE2)

| Scenario | Required behaviour |
|---|---|
| Missing `Authorization` header | 401 + `WWW-Authenticate: Bearer` response header |
| Non-Bearer scheme (`Basic ...`) | 401 |
| `Authorization: Bearer` with no token | 401 |
| Expired token (`exp` claim in the past) | 401 — always validate `exp` |
| Not-yet-valid token (`nbf` in the future) | 401 — validate `nbf` if you issue it |
| Signature with wrong secret | 401 — `hash_equals` (constant-time) |
| Tampered payload (header.different_payload.original_sig) | 401 — signature mismatch |
| `alg: none` attack (unsigned token) | 401 — reject any algorithm other than your declared one (e.g. HS256) |
| Correct token, but accessing another user's resource | 404 — see IDOR prevention |

**Always declare and enforce the algorithm.** A JWT library that does not explicitly verify `alg === 'HS256'` (or your chosen algorithm) may accept `alg: none` tokens — unsigned tokens that bypass signature verification entirely. Specify the expected algorithm in the verifier constructor, not from the token header.

**Always set `exp` on issued tokens.** A token without `exp` is valid forever. If `exp` is missing and your verifier does not reject it, a stolen token can never be revoked.

**Use `hash_equals` for all secret comparisons.** String comparison with `===` has timing side-channels. `hash_equals` takes constant time regardless of where the strings diverge.

```php
// Correct constant-time comparison
if (!hash_equals($expectedToken, $incomingToken)) {
    throw new \RuntimeException('Invalid token');
}
```

### IDOR via JWT claims

When the JWT `sub` (subject) claim identifies the user, all database queries for owned resources must use the claim value as the owner filter — not the id from the URL:

```php
// WRONG — user in URL can mismatch the authenticated user
$userId = (int)$this->request->getParam('userId');
$entries = $mapper->findByUserId($userId);

// RIGHT — always use the identity from the token
$userId = (int)($jwtClaims['sub'] ?? 0);
$entries = $mapper->findByUserId($userId);
```

This is the same ownership-in-SQL pattern as `docs/development/idor-prevention.md`.

## Related

- `docs/adr/0008-optional-bearer-for-agent-routes.md` — design rationale.
- `docs/api/openapi.yaml` — `bearerAuth` security scheme + TODO operations.
- `docs/api/reference-client.md` — non-browser caller flows (Bearer pattern).
- `docs/development/production-deployment.md` — env matrix, including `NENE_AGENT_BEARER_TOKEN` and `NENE_AGENT_BEARER_USER`.
- `docs/development/idor-prevention.md` — ownership isolation patterns.
- `docs/field-trials/2026-05-field-trial-16.md` — the trial that built this.
- `class/xion/BearerAuth.php` — implementation.
- nene-mcp project (`https://github.com/hideyukiMORI/nene-mcp`) — the sister MCP server that originated this requirement.
