# ADR 0002: Adopt Field Trial Methodology

## Status

Accepted

## Context

NeNe positions itself as a small, AI-readable, renovation-style PHP framework. Its value depends on what a fresh-clone external developer (human or AI agent) actually experiences when building a small service against it. That experience cannot be observed from inside the repository — internal tests, static analysis, and code review all assume an author who already knows the conventions.

Two sibling projects by the same maintainer (`hideyukiMORI/NENE2` and `hideyukiMORI/nene2-python`) had already converged on a "field trial" practice: build a small realistic service in an external clone, record every point of friction with a stable identifier, file each finding as a focused Issue, and only run the next trial after every previous Issue is closed. Across those two projects roughly 150 trials had been completed before NeNe adopted the same shape.

The first NeNe trials (FT1 and FT2, both 2026-05-20) immediately validated the model:

- FT1 surfaced a runtime fatal that the existing CI workflow had been declaring green (fixed in PR #223), and the upstream cause — CI never starts a runtime — was closed by PR #230, which added a Docker-based HTTP smoke job.
- FT2 surfaced a missing path for converting domain errors to JSON 4xx responses from inside `TransactionManager::run()`, which was addressed by PR #244 by introducing `Nene\Xion\DomainException`.

Both trials also drove documentation improvements that were not visible from inside the repo (PR #228, #229, #231, #238, #239, #240).

The methodology itself was introduced in PR #221 with `docs/field-trials/README.md` and `docs/templates/field-trial-report.md`, but its adoption as an ongoing practice — including the friction-kind taxonomy, the decision categories, the clone layout, and the close-all-Issues-before-next-FT cadence — is an architectural-process decision that should be recorded here.

## Decision

NeNe adopts the field trial methodology as documented in `docs/field-trials/README.md` as a continuous quality practice rather than a one-off exercise.

Specifically:

- Trials are run from a fresh `git clone` placed under `../NeNe-FT/ft{N}-{topic}/`. The trial directory is independent, its `.git` / `.env` / generated artifacts stay local, and nothing from it is committed back into the framework repository except the final report.
- Trial reports live at `docs/field-trials/YYYY-MM-field-trial-{N}.md` and follow the skeleton at `docs/templates/field-trial-report.md`. Each trial gets exactly one report file; subsequent work is tracked through Issues, not by re-editing the report.
- Friction is recorded with a stable identifier (`F-N`) inline as it occurs, and consolidated in a Friction Summary table with severity (high / medium / low), kind, and decision.
- The friction-kind taxonomy includes `legacy-preserved` (a NeNe-specific category for intentionally retained legacy shapes whose fix is documentation, never a redesign) alongside `docs-gap`, `feature-gap`, `design-trade-off`, and `process-gap`.
- The decision taxonomy includes `keep-legacy` (paired with `legacy-preserved` kind) alongside `fix-in-framework`, `document`, and `defer`.
- Each finding worth acting on is filed as a focused GitHub Issue and addressed in its own PR. The loop is closed when every actionable Issue from a trial is merged or closed with a recorded rationale.
- The next trial does not start until the previous trial's Issues are closed. This keeps each trial's findings attributable to the surface it actually exercised, not to leftover friction from a previous one.

This decision does not change framework code by itself. It commits the project to running, recording, and closing field trials as part of its quality practice.

## Consequences

- A continuous evidence channel exists for documentation gaps, missing reference patterns, and runtime regressions that internal tests cannot detect. Findings come from real construction work, not speculation.
- CI and review processes are pushed to cover the surfaces that field trials exercise. FT1 directly produced the runtime-smoke CI job in PR #230, which now blocks an entire class of regressions at the PR boundary.
- The bar for new framework features rises slightly: each one is expected to be validated against an external trial within a reasonable time after merge, rather than remaining untested in real construction.
- PR cadence during the post-trial improvement window can spike. Trials typically generate three to seven follow-up Issues that should be closed before the next trial; merging them sequentially can produce ten-plus PRs in a short window. Reviewers should expect this rhythm and may want to bundle non-conflicting follow-ups when the trial's findings cluster.
- Disk usage grows under `../NeNe-FT/` while trials are active. Trial directories should be deleted once their work closes, unless a specific clone has independent reference value.
- The `legacy-preserved` kind and `keep-legacy` decision make it explicit that not every friction is a defect. NeNe stays a renovation, not a redesign, and findings that reveal an intentional legacy shape end in documentation rather than code change.
- A future trial may surface a friction that the methodology itself produced (for example, report-writing overhead or false-positive findings). When that happens, update `docs/field-trials/README.md` and reference this ADR in a follow-up ADR.

## Related

- PR #221 — introduced `docs/field-trials/README.md` and `docs/templates/field-trial-report.md`.
- `docs/field-trials/2026-05-field-trial-1.md`, `docs/field-trials/2026-05-field-trial-2.md` — first two trials executed under this methodology.
- ADR 0001 — recording architecture decisions.
- `docs/roadmap.md` Phase 7 — Field Trials as a continuous quality gate.
