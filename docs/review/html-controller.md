# HTML Controller Self-Review

Use this checklist for server-rendered pages — new `actionAction()` handlers, Smarty templates, asset auto-discovery files, form POST handling, HTML login / logout flows.

Source policies:

- `docs/tutorials/building-a-service.md` (sections "Add a Fixed Page", "Handle a Form POST", "Protect an Authenticated Form", "Add an HTML Login Form")
- `docs/frontend/assets.md` (template, CSS, JS auto-discovery; Smarty escape behavior)
- `docs/development/coding-standards.md`

## Checklist

- [ ] Controller URL segment is a **single lowercase word** (`note`, `privatenote`), not kebab-case. The dispatcher's `ucfirst(strtolower(...))` cannot form a class name with hyphens.
- [ ] HTML actions use the `actionAction()` shape (e.g. `indexAction`, `itemAction`). Method-specific handlers (`indexPostRest`) are **not** added for HTML pages — let the dispatcher fall through to `actionAction` and branch on `$this->method` internally.
- [ ] Side-effect actions (`createAction`, `deleteAction`, `logoutAction`, ...) guard with `$this->method !== 'POST'` before performing any write; GET on a side-effect URL must not trigger the side effect.
- [ ] Form POST handlers read input via `$this->request->getPost($key)`, not `$_POST` directly.
- [ ] Validation failure re-renders the same form using `$this->VIEW->setTemplate('xxx/yyy.tpl')` (the auto-template would be the list, not the form).
- [ ] Authenticated state-changing forms use the CSRF helpers: controller sets `t_csrf_token` via `$this->csrfToken()`, template emits `<input type="hidden" name="csrf_token" value="{$t_csrf_token}">`, handler calls `$this->verifyCsrfFromPost()` before the write.
- [ ] Post-write redirects use `$this->location('/path')` (the post/redirect/get pattern); URI normalization is handled by `location()` (no leading-slash double-up — see PR #269).
- [ ] `SESSION_CHECK = false` is set in `preAction()` only for public pages (`/auth/login` etc.). Protected pages keep the default.
- [ ] Per-controller redirect targets (e.g. admin pages → `/admin/login`) use the `unauthorizedRedirect()` hook (ADR-0004), not by editing `LOGOUT_URI` or re-implementing `sessionCheck()`.
- [ ] Templates `{extends file='layout/app.tpl'}` and define their content inside `{block name='content'}`.
- [ ] Smarty auto-escapes `{$variable}` (`setEscapeHtml(true)` is on framework-wide). Markup-emitting modifiers like `|nl2br` need `nofilter`, or use CSS `white-space: pre-line` for line breaks. See `docs/frontend/assets.md` "Smarty HTML Escaping".
- [ ] Per-action / per-controller CSS / JS files live under `htdocs/css/{controller}/{action}.css` etc. and are auto-discovered. Manual `addCSS()` / `addJS()` only for CDN URLs or files outside the convention.
- [ ] A focused HTTP smoke test under `tests/Http/` exercises the happy path + at least one failure mode (e.g. unauth redirect, missing CSRF).
- [ ] `composer test` and `composer test:http` both pass.
- [ ] PR body lists this checklist.
