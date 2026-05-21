# Reference Client

How an external HTTP consumer authenticates against NeNe's REST endpoints.

The NeNe sample React client and the in-repo PHPUnit HTTP test client both handle the auth flow transparently, so neither makes the mechanics explicit. A consumer building their own client (curl script, Go service, mobile app, etc.) has to implement the four steps below.

## The four mechanics

1. **Capture the session cookie.** `POST /session/login` sets `Set-Cookie: PHPSESSID=...; HttpOnly; SameSite=Lax`. The session id is regenerated on every successful login, so do not assume the cookie persists across re-logins.
2. **Send the cookie on every subsequent request.** All authenticated endpoints expect `Cookie: PHPSESSID=<value>`. Without it, the response is `401 SESSION-CLOSED`.
3. **Capture the CSRF token.** The login response body contains `Data.csrfToken` (hex string). The token is created once at login and stays valid for the lifetime of that session — there is no rotation. Logout discards it on the server side.
4. **Send the CSRF token on state-changing requests.** Add `X-CSRF-Token: <token>` to every `POST`, `PUT`, `PATCH`, and `DELETE` while logged in (including `POST /session/logout`). Without it, the response is `403 CSRF-TOKEN-INVALID`. `GET` requests do not need the header.

`SESSION_CHECK` runs before the CSRF check, so a logged-out client calling `POST /memo/index` gets `401 SESSION-CLOSED`, not `403 CSRF-TOKEN-INVALID`.

## Sample: curl

```bash
# 1. Login → cookie jar holds PHPSESSID; CSRF token comes from the response body.
LOGIN=$(curl -s -c /tmp/nene.jar -X POST http://localhost:8080/session/login \
  -H 'Content-Type: application/json' \
  -d '{"user_id":"admin","user_pass":"admin"}')
CSRF=$(printf '%s' "$LOGIN" | python3 -c 'import json,sys; print(json.load(sys.stdin)["Data"]["csrfToken"])')

# 2. GET — only the cookie is required.
curl -s -b /tmp/nene.jar http://localhost:8080/memo/index

# 3. POST/PUT/PATCH/DELETE — cookie + X-CSRF-Token.
curl -s -b /tmp/nene.jar -X POST http://localhost:8080/memo/index \
  -H 'Content-Type: application/json' \
  -H "X-CSRF-Token: $CSRF" \
  -d '{"body":"hello from curl"}'

# 4. Logout also needs the token.
curl -s -b /tmp/nene.jar -X POST http://localhost:8080/session/logout \
  -H "X-CSRF-Token: $CSRF"
```

## Sample: fetch (browser or Node 18+)

```ts
const BASE = 'http://localhost:8080';
let csrfToken = '';

async function call(method: string, path: string, body?: unknown) {
  const init: RequestInit = {
    method,
    credentials: 'include', // sends and stores the PHPSESSID cookie
    headers: {
      ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}),
      ...(method !== 'GET' && csrfToken ? { 'X-CSRF-Token': csrfToken } : {}),
    },
    body: body !== undefined ? JSON.stringify(body) : undefined,
  };
  const res = await fetch(BASE + path, init);
  const json = await res.json();
  if (json?.Data?.csrfToken) csrfToken = json.Data.csrfToken;
  return { status: res.status, json };
}

await call('POST', '/session/login', { user_id: 'admin', user_pass: 'admin' });
await call('POST', '/memo/index', { body: 'hello from fetch' });
await call('POST', '/session/logout');
```

`credentials: 'include'` is required for the browser to send and store the cookie. In a non-browser fetch (Node 18+), the global `fetch` does the same as long as the runtime persists cookies across calls; otherwise wrap the cookie jar manually.

## Where the token lives

- The session cookie (`PHPSESSID`) is `HttpOnly` and `SameSite=Lax`. JavaScript cannot read it; the browser handles it. Set `Secure` in production via `NENE_SESSION_SECURE=1`.
- The CSRF token is returned in the JSON body — not a cookie — and the client is expected to hold it in memory for the duration of the session. Do not persist it to local storage or send it cross-origin; if the token leaks, an attacker with a valid session id can impersonate the user.

## Session id regeneration on login

`POST /session/login` calls `session_regenerate_id(true)` server-side. As a side effect, the response carries **two** `Set-Cookie: PHPSESSID=...` headers — first the old session id (immediately invalidated), then the newly regenerated one. Your client must keep the **last** `PHPSESSID=` value, not the first.

Most HTTP clients handle this correctly because they update the cookie store as headers stream in. Hand-rolled clients that loop over headers manually — `curl -c jar` with custom parsing, raw `file_get_contents` + `$http_response_header`, low-level socket reads — must iterate `Set-Cookie` headers and take the last one with a matching name:

```php
// Hand-rolled cookie extraction: take the LAST PHPSESSID=, not the first.
$latest = '';
foreach ($http_response_header as $line) {
    if (preg_match('/^Set-Cookie:\s*PHPSESSID=([^;]+)/i', $line, $m)) {
        $latest = $m[1];
    }
}
```

Using the first `PHPSESSID=` keeps the old, already-invalidated session id and every subsequent request will appear unauthenticated.

## Notes for HTML login (not REST)

If you implement a server-rendered login form rather than calling REST `/session/login`, the same `AuthSession::login(array $user)` API is used internally. The `$user` array is the row returned by `UserMapper::findByCredentials($user_id, $user_pass)`; pass it through directly. The fields read by `AuthSession::login()` are `id`, `user_id`, `user_name`, and `e_mail` — any extra columns on the row are ignored. See `docs/tutorials/building-a-service.md` for the HTML login pattern.

## Failure modes

| Response | Cause | Fix |
| --- | --- | --- |
| `401` `SESSION-CLOSED` | No or expired `PHPSESSID` cookie | Log in again, then resend the request with the new cookie. |
| `401` `LOGIN-FAILED` | `/session/login` credentials rejected | Check `user_id` / `user_pass`. |
| `403` `CSRF-TOKEN-INVALID` | Missing or wrong `X-CSRF-Token` on a state-changing request | Re-read `Data.csrfToken` from the most recent login response and resend. |
| `405` `METHOD-NOT-ALLOWED` | Wrong HTTP method for the endpoint | Check `Allow` response header. |

For the full error catalog, see `config/error_codes.php`.

## See also

- `docs/tutorials/building-a-service.md` — authoring side: how to write a controller that enforces this flow.
- `docs/api/openapi.yaml` — the `sessionCookie` and `csrfToken` security schemes used by every authenticated operation.
- `tests/Http/HttpClient.php` — in-repo reference implementation; transparently handles steps 1–4.
