# Docs and ADR Self-Review

Use this checklist for policy docs, ADR additions, roadmap / milestones / TODO updates, and the `docs/field-trials/follow-ups.md` file.

Source policies:

- `docs/workflow.md`
- `docs/CONTRIBUTING.md`
- `docs/adr/README.md`
- `docs/adr/0001-record-architecture-decisions.md`
- `docs/development/commit-conventions.md`

## Checklist

- [ ] The source-of-truth policy doc was updated instead of adding a summary in a tutorial or README that drifts.
- [ ] When project state changes (next FT theme, an Issue closes a milestone, etc.), `docs/roadmap.md`, `docs/milestones/`, and/or `docs/todo/current.md` are updated in the same PR.
- [ ] Major architecture / public contract / dependency / release-policy decisions are recorded as ADRs under `docs/adr/` and numbered sequentially.
- [ ] ADRs use the `Status / Context / Decision / Consequences / Related` shape from ADR 0001.
- [ ] Cross-links between docs use relative markdown links (`[..](../api/openapi.yaml)`), not absolute URLs.
- [ ] `docs/field-trials/follow-ups.md` is updated when a deferred FT finding is escalated to an Issue and removed when its spawning Issue is merged (per the file's own rules).
- [ ] `docs/field-trials/README.md` is the methodology source of truth; per-trial details belong in the trial report, not in README.
- [ ] Tutorial examples use the same convention names the framework actually uses (e.g. `privatenote` not `private-note` per PR #286).
- [ ] If a checklist in `docs/review/` is touched, the linked source policy is checked for the corresponding change.
- [ ] Markdown renders without broken links or table glitches (`grep -nE 'broken\\|\\\\|\\(\\)' docs/...md` for hand-typed tables).
- [ ] PR body lists this checklist when docs / ADRs change.
