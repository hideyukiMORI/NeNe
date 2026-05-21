# Reflection — After Six Field Trials (2026-05-20 → 2026-05-21)

A single-pass impression written by the AI agent (Claude, via Claude Code) that drove FT1 through FT6 alongside the maintainer. This is not a methodology document and not a per-trial report — those live in `2026-05-field-trial-{1..6}.md`. The intent here is to record a cumulative read of NeNe as a framework after running six trials back-to-back, before the impression fades.

Format: candid notes, not a sales pitch. Some of what is below is uncomfortable; that is by design.

## Overall touch

NeNe lives up to "renovated legacy" as a literal claim, not a rebrand. Tracing URL → controller → action gets you to the relevant behavior in seconds — a feeling that came back as a positive surprise in every trial. Modern frameworks that hide routing behind annotations or registration calls have normalized "30 minutes to first edit." NeNe is genuinely 5 minutes. That posture is a kept design choice, not an accident.

## What turned out better than expected

- **Auth docs gap hypotheses (H-A through H-F in FT3) all unfired.** Going in I expected the CSRF / session story to be poorly documented from a build-it-from-scratch angle. `docs/tutorials/building-a-service.md` already covered the four mechanics; `MemoController` was built without reading framework source.
- **HTML side worked first-try (FT4 F-9).** Layout extension via `{extends file='layout/app.tpl'}` + `{block name='content'}`, `setTitle()`, asset auto-discovery for `htdocs/css/note/common.css` — none of it needed inspection. The conventions are well-formed.
- **Framework anticipates HTML auth (FT5 F-9 / F-10).** `ControllerBase::sessionCheck()` already branches REST vs HTML and redirects to `LOGOUT_URI` for HTML; `AuthSession`'s public surface (`login` / `logout` / `isLoggedIn` / `userId` / `csrfToken` / `verifyCsrfToken`) is directly usable from an HTML controller. The author thought about both directions even though only REST shipped with a sample controller.
- **`cli/setupDatabase.php` is production-quality.** `--env` / `--yes` / `--help`, idempotent, dual MySQL / SQLite path, clear failure messages. FT6 is the first CLI-only trial and the surface needed almost no fixing — only docs and a `composer setup` shortcut.

## The pattern that kept repeating

Across the six trials, the most consistent friction was **hard-coded constants** and **schema duplication**. Concretely:

- **FT3 F-1** — OpenAPI failure envelopes ballooned per error code; resolved by ADR 0003 collapsing to a single `ApiFailureEnvelope`.
- **FT5 F-2** — `LOGOUT_URI` was a hard `const`, no env override; resolved by PR #284 using the `$getEnv` helper pattern that already existed elsewhere in `ini/xSystemIni.php`.
- **FT5 F-3** — `sessionCheck()` was `final`, no controller-level customization; resolved by ADR 0004 adding `unauthorizedRedirect()` as a hook.
- **FT6 F-2** — schema authored in three independent sites (`docker/mysql/init/001_schema.sql`, `cli/initSQLite.php`, `class/xion/DatabaseInstaller.php`); documented but consolidation deferred to a future ADR.

These aren't bugs — they are the residue of an older PHP convention being preserved into a current renovation. The discipline of preserving the legacy shape works as a design value, but it leaves a tail of "if you want to vary this, you edit framework source." The trials surface that tail one piece at a time. By FT6, two-thirds of the pattern was already eliminated or escalated.

## Implicit HTML conventions

HTML-side conventions are powerful when known and invisible when not. Examples surfaced across FT4–FT5:

- URL controller segments must be single lowercase words (`privatenote`), not kebab-case (`private-note`) — the dispatcher's `ucfirst(strtolower(...))` cannot form a class name with hyphens (FT5 F-5).
- HTML form CSRF is a three-step ritual (controller passes token to template, template emits hidden field, handler verifies) with no framework helper before PR #289 (FT5 F-4).
- `actionAction()` is HTTP-method-blind; side-effect actions need a `$this->method !== 'POST'` guard (FT4 F-1, extended in FT5 F-6).
- The asset auto-discovery rules — `htdocs/{css|js}/{controller}/{action}.{css|js}` — were unwritten until PR #270 (FT4 F-5).
- Smarty's framework-wide `setEscapeHtml(true)` interacts badly with markup-emitting modifiers like `|nl2br`; either use `nofilter` or sidestep with CSS `white-space: pre-line` (FT4 F-3).

None of these are framework defects. They are tribal knowledge that the trials forced into the tutorial. The contributor who runs the next trial will arrive at a much friendlier surface than I did.

## Character of the framework

NeNe is small on purpose. `class/xion/` is genuinely readable end-to-end. `ControllerBase::run()` fits in thirty lines and shows the entire dispatch shape. This is the opposite of "trust the magic" frameworks, and it pays directly into reviewability — the Phase 6 thesis is grounded.

"Small" does not mean "missing." OpenAPI contract + CSRF + password hashing + session cookie attributes + Docker development + dual database + tests + ADR practice + field-trial methodology are all present. The framework knows what it does not need, which is a harder property than it sounds.

## The methodology is the headline win

Looking past the framework itself, the **field-trial cadence** (ADR 0002) is what made the 24-hour FT3 → FT6 push possible:

- Each trial produced concrete findings (`F-N`) that turned directly into closed Issues.
- Deferred findings (`docs/field-trials/follow-ups.md`) had re-evaluation triggers, so escalation was rule-based rather than negotiation-based. FT2 F-5 → ADR 0003 was a clean example of the trigger firing.
- The post-trial improvement window (close every spawned Issue before the next trial) kept findings from piling up. Six trials in succession produced a clean state at the end, not a backlog.
- Cross-project pattern import worked: PR #291 borrowed NENE2's `docs/review/` shape and adapted it. NeNe and NENE2 share workflow DNA without duplicating it word for word.
- An honest self-debugging incident landed in `feedback-ci-debugging-discipline` memory (FT5: a PHP `const` syntax error was first misdiagnosed as a CI timeout). That kind of feedback persistence raises future trial quality.

Trials are not free, but the cost-per-finding is low and stable. After six runs the curve has bent: surface-level docs gaps are largely gone, what surfaces now is structural (FT6 F-2 schema consolidation is the next ADR candidate). That is the right shape.

## What I would tell a contributor arriving now

1. Read `docs/tutorials/building-a-service.md` once end to end. Every section now has a runnable example, including the HTML form POST / Protect an Authenticated Form / HTML login form pieces added during FT4–FT5.
2. Read `docs/review/README.md` and pick the matching checklist before opening a PR. The checklists are short and they link back to the source policy.
3. When unsure whether something is convention or framework rule, search `class/xion/` directly. The codebase is small enough to read.
4. Start the next trial with `tools/nene-ft-new.sh {topic}` — bootstrap is now a one-shot.
5. Pick a surface FT1–FT6 has not touched (error pages, production-mode deployment, Smarty custom plugins, OpenAPI authoring workflow round-trip, schema consolidation). Re-testing already-exercised paths gives diminishing returns.

## Closing one-liner

NeNe is a framework that is genuinely small and genuinely reviewable. Six trials worth of friction came mostly from aging artifacts (hard-coded constants, duplicated schemas, undocumented HTML conventions), not from architectural mistakes. The legacy shape is a real design value, and the renovation pieces (OpenAPI, CSRF, ADRs, tests, Docker, FT methodology) sit on top of that shape without overgrowing it. The continuous-trial methodology is doing exactly what it should: catching what an internal author cannot see, and converting it to PRs faster than the friction can accumulate.
