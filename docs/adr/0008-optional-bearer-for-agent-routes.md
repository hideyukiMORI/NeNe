# ADR 0008: Optional Bearer Authentication for Agent / MCP Routes

## Status

Accepted

## Context

NeNe's stock REST surface authenticates via PHP session cookie plus a `X-CSRF-Token` header for state-changing requests. That works perfectly for browser front-ends and for tools that can maintain a cookie jar (curl with `-c/-b`, Postman, etc.), and it is the right default for a small framework that ships an HTML sample app.

It does **not** work for **stateless** API clients. Field trials in the sister project **nene-mcp** (`hideyukimori/nene-mcp`, an MCP server that proxies between LLM agents and NeNe-shaped REST APIs) confirmed this concretely:

- nene-mcp FT204 (2026-05): the standard MCP transport (stdio + JSON-RPC) has no persistent state across tool calls. Login succeeds, but the returned `PHPSESSID` cookie is dropped before the next `listTodos` call can use it.
- nene-mcp FT215: even when login is honored, the response's `Data.csrfToken` needs to land in a per-write `X-CSRF-Token` header — extra plumbing inside the MCP layer.
- nene-mcp FT225–FT419: the persona / L6 long-run trials reproduced the same failure mode dozens of times across different prompts.

The cross-repo Issue (#380) opened a concrete handoff: NeNe should optionally accept a **Bearer token** on the agent REST surface so stateless clients work end-to-end without cookie+CSRF plumbing.

### Constraints from the trial

1. **Browser flow must be unchanged.** Any solution that touches session cookies or alters CSRF for the cookie path is unacceptable — it would regress every existing controller and every browser user.
2. **Authentication is stateless on the server side.** The Bearer token alone authenticates the request; no session is created, no session is consumed.
3. **The Bearer is the proof of intent.** If the request carries a valid Bearer, CSRF protection is redundant (CSRF defends against cross-site form submission, which is a browser-only attack surface).
4. **The framework must not invent a token store.** A real personal-access-token (PAT) implementation is its own architecture work (DB table, hashing, expiry, per-user scopes); FT16 is not the place for it.
5. **The mod_php quirk** (Apache strips `Authorization` from `$_SERVER`) needs handling in the framework — every consumer should not have to rediscover it.

### Options considered

| Option | What it does | Why not |
| --- | --- | --- |
| **A — JWT** with public-key signing | RS256 JWT verified per request | Significant dep tree (`firebase/php-jwt` or `lcobucci/jwt`); JWKS rotation story; per-claim parsing rules; overkill for "single MCP integrator wants in" |
| **B — Per-user PAT table** | DB table `personal_access_tokens` with hashed values | Right shape long-term, but requires schema + UI + rotation + scope semantics. Out of FT16's scope. |
| **C — Single env-driven Bearer mapping to one configured user** | `NENE_AGENT_BEARER_TOKEN` and `NENE_AGENT_BEARER_USER`; `hash_equals` on inbound | Smallest viable surface. Maps one external secret to one DB user. Operators rotate by changing env. Acceptable for "first MCP integrator" and any small project that has one or two integrators. |

Option C lines up with NeNe's "small framework, env-driven config" character (ADR-0004 / ADR-0006 / ADR-0007 all use the same env pattern). It also keeps the door open for Option B as a future ADR — the helper class shape (`BearerAuth::resolve()`) is identical; only the lookup changes from "env compare" to "DB lookup."

## Decision

- Introduce `Nene\Xion\BearerAuth` — pure-static. `resolve()` is called once per request from `htdocs/index.php` immediately after `session_start()`. On a valid `Authorization: Bearer <token>` header (matched in constant time against `NENE_AGENT_BEARER_TOKEN`), it looks up the user identified by `NENE_AGENT_BEARER_USER` (default `admin`) via `UserMapper::findRowByUserIdentifier()` and binds it to `AuthSession`.
- Add `AuthSession::bindBearer(array $user): void` and `isBearerAuthenticated(): bool`. The bearer-bound user lives in an instance field, **not** in `$_SESSION`. The PHP session cookie is never touched.
- Add a fourth parameter to `CsrfProtectionPolicy::requiresToken(...)` — `bool $isBearerAuthenticated = false`. When true, the policy short-circuits to false. Default false preserves every existing call site.
- The Bearer header is read via a four-layer fallback (`$_SERVER['HTTP_AUTHORIZATION']` → `REDIRECT_HTTP_AUTHORIZATION` → `apache_request_headers()` → `getallheaders()`) so the helper works under Apache mod_php, PHP-FPM, and any future SAPI without per-deploy Apache config.
- Two new env vars: `NENE_AGENT_BEARER_TOKEN` (empty by default — feature off) and `NENE_AGENT_BEARER_USER` (default `admin`).
- Update `docs/api/openapi.yaml` to add `bearerAuth` security scheme; TODO operations carry both `sessionCookie` (and `csrfToken` for writes) **and** `bearerAuth` as alternative security requirements.
- Document the design and operator concerns in `docs/development/agent-bearer-auth.md`.

The feature is **off by default**. With `NENE_AGENT_BEARER_TOKEN` unset, the framework behaves exactly as before this ADR. There is no surface area cost for deployments that never opt in.

## Consequences

Positive:

- Stateless MCP / agent clients work end-to-end against NeNe TODO (and any future agent-facing route that opts into `bearerAuth` in its OpenAPI security list).
- The cross-repo handoff with nene-mcp is closed: their confirmation FT can fire against this ADR's implementation.
- Future per-user PAT (Option B) is a drop-in extension — only `BearerAuth::lookupUser()` changes from env-compare to DB-lookup. The public surface (`resolve()` / `bindBearer()`) stays.
- The `requiresToken` signature change is backward-compatible (default `false`); no existing controller code needs to change.

Negative / accepted trade-offs:

- **One env token maps to one DB user.** A real production deploy with multiple integrators would either share the token (sub-optimal — no per-caller audit) or wait for Option B. Acceptable for FT16's scope (single nene-mcp integrator).
- **No per-token scopes.** A valid Bearer authenticates as the configured user — full read/write of that user's data. Multi-tenant scopes are Option B's job.
- **Rotation is operator-side.** Changing `NENE_AGENT_BEARER_TOKEN` invalidates the existing token and requires the integrator to update theirs. No graceful "old + new" overlap. Acceptable for a single integrator; a real PAT table would handle overlap.
- **mod_php Authorization quirk is opaque.** The four-layer fallback works, but the underlying behavior is undocumented Apache mod_php "security hardening" that strips a header silently. Any future framework consumer that needs other inbound headers may rediscover the same pattern.

Neutral:

- CSRF is unaffected for browser flows. Only Bearer-authenticated requests skip CSRF — the policy's existing behavior for cookie-session callers is unchanged.
- The bearer-bound user persists for exactly one request. Subsequent requests must re-authenticate. This is the correct stateless property.
