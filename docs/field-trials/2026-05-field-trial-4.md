# Field Trial 4 — smarty-html (Server-rendered HTML pages)

Methodology reference: `docs/field-trials/README.md`. Report skeleton: `docs/templates/field-trial-report.md`. Trial Issue: #261.

## Date

2026-05-21

## Baseline

- NeNe ref: `9c86e43` (post #258 / #259 / #260 — ADR-0003 + generic `ApiFailureEnvelope` + CI MySQL health 待機 + `tools/nene-ft-new.sh` 投入後の main)
- Clone path: `/home/xi/github/NeNe-FT/ft4-smarty-html/` (created via `tools/nene-ft-new.sh smarty-html`)
- Host ports: app=8084, mysql=3311 (port offset from the bootstrap script, so the clone runs alongside the framework without collision)
- PHP: 8.4.21 (in `php:8.4-apache` container)
- Database: MySQL 8.4 (Docker Compose default), SQLite parity verified via `cli/initSQLite.php`
- Other tooling: PHPUnit 10.5.63, Composer 2.9.8, jq 1.6, curl 8

### Baseline verification

| Check | Result |
| --- | --- |
| `tools/nene-ft-new.sh smarty-html` | clone + `.env` + `.claude/{settings.local.json, CLAUDE.md}` + `FT4-PLAN.md` 全部投下、所要 ~5s |
| `docker compose up -d app` | `/health` `healthStatus=ok` within 1s |
| `composer test` | 45 / 45, 129 assertions |
| `composer test:http` (NENE_HTTP_BASE_URL=http://127.0.0.1) | 21 / 21, 205 assertions, 1 expected skip |

All FT1/2/3 fixes plus the FT3-era infra additions (FT bootstrap script #257, CI health 待機 #259, CLAUDE.md skeleton #260) worked end-to-end. No baseline friction.

## Goal

Exercise the HTML side of NeNe — the surface FT1 (REST + DB), FT2 (M:N + transaction), and FT3 (auth + CSRF) all explicitly skipped:

1. `actionAction()` HTML handler convention
2. Smarty template structure (`view/source/{controller}/{action}.tpl`, `{extends file='layout/app.tpl'}` + `{block name='content'}`)
3. ViewModel pattern (`$this->VIEW->setString()` / `setValues()` / etc.)
4. Asset auto-discovery (`htdocs/css/{controller}/{action}.css` / `htdocs/js/...`)
5. Layout system (`view/source/layout/app.tpl` extends, block override)
6. Form submission flow (HTML form → controller → redirect)
7. Smarty compile cache (`view/compile/`)
8. Title / metadata (`setTitle()`, layout reference)

Authentication is intentionally out of scope (FT3 already covered it). The trial focuses purely on rendering and form posting.

## Service Built — notebook

A simple `Note` entity rendered as HTML pages. One entity, no auth, no REST.

### Schema (parallel changes in two locations)

`docker/mysql/init/001_schema.sql` and `cli/initSQLite.php` both received the same `notes` table (id, created_at, updated_at, title VARCHAR(255), body TEXT, is_deleted). SQLite also got a `notes_updated_at_trigger` to mirror MySQL's `ON UPDATE CURRENT_TIMESTAMP`.

### Pages

| HTTP method | Path | Handler | Notes |
| --- | --- | --- | --- |
| GET | `/note/index` | `indexAction()` | list view |
| POST | `/note/index` | `indexAction()` | form submit (`$this->method === 'POST'` 分岐) → 302 redirect — see F-1 |
| GET | `/note/item/id_X` | `itemAction()` | detail view |
| GET | `/note/new` | `newAction()` | form view |

`NoteController::preAction()` sets `SESSION_CHECK = false` because the trial is about HTML, not auth.

### Templates

Four templates under `view/source/note/`:
- `index.tpl` — list with foreach + empty state
- `item.tpl` — detail (article body, back link)
- `new.tpl` — form with validation-error display

All extend `layout/app.tpl` via `{extends}` + `{block name='content'}`. The layout reads `$t_title`, `$t_css[]`, `$t_js[]` set by `ControllerBase`.

### Assets

- `htdocs/css/note/common.css` — auto-discovered as `/css/note/common.css`
- `htdocs/js/note/common.js` — auto-discovered as `/js/note/common.js`

### Tests

A new `tests/Http/NoteHtmlTest.php` covers:
- Empty-state list rendering
- New-note form rendering
- POST with empty fields → validation error template
- POST with valid data → 302 → detail page → list reflects it
- Unknown id and non-numeric id → 404

Suite totals after FT4:
- `composer test`: 45 / 45, 129 assertions (no regression)
- `composer test:http`: 27 / 27, 249 assertions, 1 expected skip (21 existing + 6 new)

## Steps Taken

1. **Trial clone bootstrap** via `tools/nene-ft-new.sh smarty-html`. The script auto-detected FT4, set ports 8084/3311, dropped settings and PLAN skeleton in ~5 seconds — no manual setup required.
2. **Baseline verified**. All previous trial fixes plus the FT3-era infra additions work cleanly.
3. **Schema added in two files** following the parallel-maintenance note from FT2 (PR #240). Verified parity by inspecting `/health` after `docker compose down -v && up -d`.
4. **Model + Mapper** (`Note`, `NoteMapper`). The mapper exposes `findRows`, `findRowById`, `create` — no per-user scoping since the trial has no auth.
5. **Controller** (`NoteController`) with three actions (`indexAction`, `itemAction`, `newAction`). `indexAction` handles both GET (list) and POST (form submit) via `$this->method` dispatch — surfacing F-1.
6. **Templates + assets** written. `setTitle()`, `setString()`, `setValues()`, auto-discovery of `/css/note/common.css` and `/js/note/common.js` all worked first try (F-9).
7. **Smoke verified manually via curl**. GET pages and POST → 302 → detail flow worked. `location('/note/item/id_1')` produced a `Location: //note/item/id_1` header (double slash) — F-2.
8. **First Smarty surprise (F-3)**: my initial detail template used `{$body|escape:'html'|nl2br}`. Smarty's `setEscapeHtml(true)` global escapes the *output* AFTER modifiers, so `nl2br` added `<br />` that the auto-escape then re-encoded to `&lt;br /&gt;`. Workaround: drop nl2br entirely and use CSS `white-space: pre-line` on the body div.
9. **Compile cache friction (F-4)**: tried to `rm view/compile/*` from the host to refresh a stale template; got Permission denied because the directory is owned by the container's www-data user. Resolved by `docker compose exec -T app find view/compile -type f -delete`.
10. **HTTP test** — went through three iterations (initial double-escape, then needed `Nene\Xion\PdoConnection` instead of `Nene\Database\PdoConnection`, then needed `Initialize::init()` to load DB_TYPE constants for direct PDO use). All small but documents a pattern gap (F-8).
11. **Suite green** under both unit (45 / 45, 129) and HTTP (27 / 27, 249, 1 expected skip).

## Results

| Scenario | Expectation | Actual | Status |
| --- | --- | --- | --- |
| Two-file schema add (MySQL + SQLite) | `/health` shows `ok` after recreate | works | Pass |
| Layout extension + setTitle | `<title>` reflects controller-set value | works first try | Pass (F-9) |
| Asset auto-discovery (CSS + JS) | `<link>` / `<script>` emitted without explicit register | works first try | Pass (F-9 / F-5) |
| Empty-state list rendering | "No notes yet" shown | works | Pass |
| Form POST with empty fields | validation error re-renders new.tpl | works after explicit `setTemplate()` call | Pass (with F-7) |
| Form POST with valid data | 302 → detail page | works | Pass (with F-2 cosmetic issue) |
| `location('/note/...')` Location header | clean `/note/item/id_X` | `Location: //note/item/id_X` (double slash) | F-2 |
| `{$body\|nl2br}` for line breaks | line breaks become `<br>` | `<br>` got auto-escaped to `&lt;br /&gt;` | F-3 |
| Unknown id detail / non-numeric id | 404 | works (via `notFound()`) | Pass |
| `composer test:http` | all pass | 27 / 27, 249, 1 expected skip | Pass |
| `composer test` regression | 45 / 45 still | yes | Pass |

## Friction Summary

| ID | Location | Severity | Kind | Decision |
| --- | --- | --- | --- | --- |
| F-1 | `class/xion/Dispatcher.php` HTML POST = same action as GET; tutorial silent on form-post handling | medium | docs-gap | document |
| F-2 | `class/xion/ControllerBase::location()` produces double-slash when `URI_ROOT='/'` | low | feature-gap | fix-in-framework |
| F-3 | Smarty `setEscapeHtml(true)` interaction with `\|nl2br` and other markup-emitting modifiers | medium | docs-gap | document |
| F-4 | `view/compile/*` owned by container user; host `rm` fails | low | docs-gap | document |
| F-5 | Asset auto-discovery convention (`{ctrl}/{action}.css\|.js`) absent from tutorial | medium | docs-gap | document |
| F-6 | `$this->request->getPost()` undocumented in tutorial (REST `REQUEST_JSON` shown only) | low | docs-gap | document (consolidate into F-1) |
| F-7 | `setTemplate('explicit/path.tpl')` for validation re-render pattern undocumented | low | docs-gap | document (consolidate into F-1) |
| F-8 | HTTP test needs `Initialize::init()` to touch framework classes (PdoConnection etc.) directly | low | docs-gap | defer (test-specific) |
| F-9 | Layout + setTitle + asset auto-discovery worked first try | n/a (positive) | none | no action |

### Hypotheses outcome

| # | Hypothesis | Materialized? |
| --- | --- | --- |
| H-A | `setString()` / `setArray()` usage undocumented | partial (rolled into F-5 and F-7) |
| H-B | Form POST → controller path unclear | yes (F-1 + F-6) |
| H-C | redirect helper unclear | helper exists but F-2 surfaced |
| H-D | Layout block override convention undocumented | **no** — clear from reading `app.tpl` once |
| H-E | Smarty compile cache deletion procedure undocumented | yes (F-4) |
| H-F | CSS / JS auto-discovery convention undocumented | yes (F-5) |

H-D was wrong: the layout file is short and self-explanatory once read. H-A's specifics rolled into other findings.

## Recommendations

### Immediate (documentation only)

1. **F-1 + F-6 + F-7** — Add a "Handle a form POST" section to `docs/tutorials/building-a-service.md` after the existing "Add a Fixed Page" section. Cover: how dispatch picks `indexAction` for `POST /xxx/index` (because no `indexPostRest` exists), the `$this->method === 'POST'` guard pattern, `$this->request->getPost($key)` for form data, and `$this->VIEW->setTemplate('xxx/yyy.tpl')` for validation-failure re-render. Include a 30-line `NoteController`-shaped example.
2. **F-3** — Add a short subsection (or tip box) in the same tutorial or in a new `docs/frontend/smarty-modifiers.md` explaining that Smarty's `setEscapeHtml(true)` is enabled framework-wide and that markup-emitting modifiers like `\|nl2br` need `nofilter` to avoid double-escape. Recommend the CSS `white-space: pre-line` alternative for plain line breaks.
3. **F-4** — One-line note in `docs/development/docker.md`: to clear the Smarty compile cache during template development, use `docker compose exec -T app find view/compile -type f -delete` (host `rm` fails on container-owned files).
4. **F-5** — Add a Convention table to `docs/tutorials/building-a-service.md` (or to `docs/frontend/assets.md`) documenting the asset auto-discovery rules: `htdocs/{css|js}/{controller}.{css|js}` → top-level, `htdocs/{css|js}/{controller}/common.{css|js}` → per-controller shared, `htdocs/{css|js}/{controller}/{action}.{css|js}` → per-action. Cross-link from the "Add a Fixed Page" section.

### Suggested (small framework change)

5. **F-2** — Normalize `ControllerBase::location($uri, $flag=true)` to avoid `//path` headers. One-line change: `URI_ROOT . ltrim($uri, '/')` (when `$flag = true`). No behavior change for browsers (they normalize anyway), but the response header becomes clean for consumers / tests / logs.

### Deferred

6. **F-8** — `Initialize::init()` requirement when an HTTP test touches framework classes directly. FT4 hit it during cleanup helper authoring. Not a wide-surface friction; if a future trial re-discovers it, escalate.

### Confirmed working (positive)

7. **F-9** — Layout extension, `setTitle()`, `setString()` / `setValues()` ViewModel pattern, and the entire asset auto-discovery flow all worked first try without reading framework source. NeNe's HTML side carries the legacy framework feel its README promises — once the conventions are known.

## Overall Impression

FT4 confirmed that NeNe's HTML side is in a good state once you know the conventions. Layout extension, `setTitle()`, the ViewModel pattern, and asset auto-discovery all worked without surprises — the rendering layer is the most polished surface FT4 exercised.

The friction surface is almost entirely **documentation**, not code:

- The tutorial (`docs/tutorials/building-a-service.md`) heavily favors the REST/`indexPostRest` path and never covers HTML form POSTs. A reader following the tutorial straight through would not know how to handle a form submit (F-1, F-6, F-7).
- Asset auto-discovery is a real feature with a real convention, but the convention only lives in `ControllerBase::setCSS()` / `setJS()`. Surfacing it as a one-table doc closes the gap (F-5).
- Smarty's global `setEscapeHtml(true)` is the right default, but its interaction with markup-emitting modifiers like `\|nl2br` is a footgun without `nofilter` (F-3). One paragraph closes it.

Only one finding requires a code change: `location()` double-slash (F-2), which is cosmetic but trivially fixable.

The FT3-era infra additions paid back this trial. `tools/nene-ft-new.sh` made bootstrap a 5-second one-liner with port offset. `.claude/CLAUDE.md` (from #260) gave a fresh Claude Code session immediate context. The CI health-status check (#259) is not exercised this trial but stands ready. None of the FT4 findings cost more than a few minutes because the infra was already in place — which is exactly the trade FT3 set up.

What FT4 did not exercise but expected to: HTML CRUD with delete + update (only create was needed), CSRF for HTML forms (no auth, no CSRF), multi-controller layouts (only one new controller), nested blocks (single `content` block sufficed). FT5 candidate could combine auth + HTML to cover the protected-page flow if that surface is still untouched, but a lighter alternative is "OpenAPI authoring workflow" or "deployment story" — both surfaces FT1–FT4 have not approached.

## Follow-up Issues

To be filed under the FT4 loop (close all before starting FT5):

- F-1+F-6+F-7 (medium, docs): "Handle a form POST" tutorial section + asset/setTemplate notes consolidated into one Issue.
- F-2 (low, framework): `location()` URI normalization.
- F-3 (medium, docs): Smarty `setEscapeHtml(true)` + `\|nl2br` / `nofilter` documentation.
- F-4 (low, docs): `view/compile` cleanup tip in `docs/development/docker.md`.
- F-5 (medium, docs): Asset auto-discovery convention table.

F-8 is deferred (test-specific; recorded in `docs/field-trials/follow-ups.md`).
F-9 is positive, no action.

## Reminder

This report omits secrets, raw API keys, production endpoints, and confidential prompts.
