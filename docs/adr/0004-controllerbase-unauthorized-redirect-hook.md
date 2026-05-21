# ADR 0004: Per-controller Unauthorized-Redirect Hook

## Status

Accepted

## Context

`ControllerBase::sessionCheck()` is `final` and, on an unauthenticated HTML page, redirects to the framework-wide constant `LOGOUT_URI`. This is the right default — every HTML page needs *some* destination when the visitor is not logged in — and Issue #277 (PR #284) already made the constant environment-overridable via `NENE_LOGOUT_URI`.

What the env var cannot do is vary *per controller*. A typical application splits authenticated areas: `/admin/*` should redirect to `/admin/login`, while regular user pages redirect to `/auth/login`. With `LOGOUT_URI` as a single global constant and `sessionCheck()` marked `final`, controllers cannot override the destination without:

1. Disabling `SESSION_CHECK` entirely and re-implementing the unauth path manually in `preAction()` — works, but the framework's `sessionCheck()` still runs unnecessarily.
2. Editing the framework `const LOGOUT_URI` value, which is process-global.

Field Trial 5 (`docs/field-trials/2026-05-field-trial-5.md`, finding F-3) recorded this friction. The trial used the global override (F-2 / PR #284), but documented that per-controller redirect targets are a real need for any application with more than one authenticated section.

Two design options were considered:

- **(a)** Drop the `final` modifier from `sessionCheck()`. Subclasses could override it entirely.
- **(b)** Keep `sessionCheck()` `final`, but extract the redirect-target decision into a separate overridable method.

Option (a) is the smaller diff but lets subclasses replace the whole dispatch logic (e.g. they could skip session check, return a different protocol, etc.) — the invariant that `sessionCheck()` performs exactly one consistent check is lost.

Option (b) keeps the dispatch invariant centralized in one `final` method while opening exactly the variation that the friction demands: which URI to send unauthenticated HTML visitors to.

## Decision

Add a `protected function unauthorizedRedirect(): string` method on `ControllerBase`. `sessionCheck()` calls it instead of hard-coding the `LOGOUT_URI` lookup. The default implementation returns `LOGOUT_URI`, so existing controllers behave exactly as before.

```php
final protected function sessionCheck(): void
{
    if (!$this->AUTH_SESSION->isLoggedIn()) {
        $this->logout();
        if (!$this->ROUTE_CONTEXT->isRest()) {
            $this->location($this->unauthorizedRedirect());
        } else {
            Xion\JsonResponder::outputArray($this->API_RESPONSE->failure('SESSION-CLOSED'));
        }
    }
}

protected function unauthorizedRedirect(): string
{
    return LOGOUT_URI;
}
```

A subclass that wants a different target overrides only the hook:

```php
class AdminPanelController extends ControllerBase
{
    protected function unauthorizedRedirect(): string
    {
        return '/admin/login';
    }
}
```

The hook returns a string so callers cannot accidentally insert side effects (no HttpResponse construction, no termination — those are still owned by `sessionCheck()`).

REST controllers are unaffected. The REST branch of `sessionCheck()` continues to write JSON `SESSION-CLOSED` and does not consult the hook.

## Consequences

- Per-controller redirect customization becomes a one-method override. No need to set `SESSION_CHECK = false` and reimplement the unauth path.
- `sessionCheck()` remains `final`. The framework's session-check invariant is preserved; only the destination URI varies.
- Existing controllers and applications see no behavior change: the default `unauthorizedRedirect()` returns `LOGOUT_URI`, which itself honors `NENE_LOGOUT_URI` after PR #284.
- The `ControllerBase` inheritance contract grows by one method. Future ADRs that change unauth-handling should update both the hook and this ADR.
- `docs/tutorials/building-a-service.md` "Add Authentication Requirements" can now show "override `unauthorizedRedirect()` if your controller has its own login URL" as a one-line pattern.
- Option (a) (drop `final`) is rejected: that flexibility was not asked for by any concrete use case and would let subclasses break the session-check guarantee.

## Related

- Issue #278 — original FT5 finding F-3 that prompted this ADR.
- `docs/field-trials/2026-05-field-trial-5.md` F-3 — friction record.
- PR #284 (Issue #277) — `LOGOUT_URI` env override (the global counterpart to this per-controller hook).
- `class/xion/ControllerBase.php` — `sessionCheck()` and the new `unauthorizedRedirect()` hook.
- ADR 0002 — field trial methodology that surfaced this decision point.
