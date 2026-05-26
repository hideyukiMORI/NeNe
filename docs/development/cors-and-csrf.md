# CORS and CSRF — They Are Not the Same

A common misconception: "I have CORS configured, so I'm protected from CSRF." This is wrong. CORS and CSRF protect against different threat models.

## CORS — controls what browsers read

CORS (Cross-Origin Resource Sharing) governs whether a **browser** is allowed to read a response from a different origin. It is enforced by the browser, not the server.

If an attacker makes a request from `evil.example.com`:
- Without a permissive CORS header, the browser refuses to give the attacker's JavaScript access to the response body.
- **But the request still arrives at your server and may execute.** CORS does not prevent the request — it only blocks the attacker from reading the result.

A curl script or any non-browser client can set any `Origin` header it likes — CORS does not stop non-browser callers at all.

## CSRF — cross-site state-changing requests

CSRF (Cross-Site Request Forgery) exploits the fact that browsers automatically send cookies (including your session cookie) with every request to a domain. An attacker's page can trick a logged-in user's browser into making a state-changing request to your site — with the user's real session attached.

**CORS does not prevent CSRF** because the CSRF request is sent by the user's browser (with real credentials), not by the attacker's server. The browser's own same-origin restriction prevents the attacker from reading the response, but the server has already executed the action.

## NeNe's protection model

NeNe uses a **CSRF token in `X-CSRF-Token`** (not a cookie) for state-changing REST requests. An attacker's page cannot read the CSRF token (same-origin policy prevents cross-origin JavaScript from reading the token), so forged requests fail validation.

| Caller type | CSRF risk | Protection |
|---|---|---|
| Browser (session cookie) | Yes | `X-CSRF-Token` header required for POST/PUT/PATCH/DELETE |
| Bearer-authenticated agent | No | Bearer token is not a cookie; browsers cannot forge it |
| curl / API client | No | No real user session to exploit |

### Why JSON APIs with Bearer are CSRF-immune

When authentication uses `Authorization: Bearer <token>` instead of a session cookie:
- There is no cookie for the browser to auto-send.
- An attacker's page cannot forge an `Authorization` header (browsers' same-origin restriction prevents cross-origin reads, and setting custom headers triggers CORS preflight which the server controls).
- CSRF attacks require implicit credential transport — Bearer tokens are explicit.

This is why NeNe's `CsrfProtectionPolicy::requiresToken()` returns `false` for bearer-authenticated requests.

### When CORS matters

Configure CORS restrictively when:
- Your API is accessed by browser-based clients from a known origin.
- You want to prevent untrusted origins from reading API responses (even non-session API responses).

NeNe does not ship a CORS middleware — add a `header('Access-Control-Allow-Origin: ...')` in `htdocs/index.php` or in a `preAction()` hook if your deployment needs cross-origin access.

## Summary

| Concern | Mechanism | Who enforces |
|---|---|---|
| Prevent cross-origin JS from reading response | CORS (`Access-Control-Allow-Origin`) | Browser |
| Prevent cross-site state-changing requests by authenticated user | CSRF token | Server |
| Prevent state-changing requests without explicit credential | Bearer token + `Authorization` header | Server |

**Do not rely on CORS as a CSRF defense.** Use NeNe's `X-CSRF-Token` for browser sessions, or Bearer auth for stateless clients.

## Related

- `docs/development/agent-bearer-auth.md` — Bearer auth and JWT edge cases
- `docs/development/security-headers.md` — response security headers
- `class/xion/CsrfProtectionPolicy.php` — CSRF token enforcement
