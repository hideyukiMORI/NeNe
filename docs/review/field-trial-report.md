# Field Trial Report Self-Review

Use this checklist when adding a new `docs/field-trials/YYYY-MM-field-trial-N.md` report or its follow-up Issues. Also use it when editing `docs/field-trials/follow-ups.md`.

Source policies:

- `docs/field-trials/README.md` (methodology, friction-kind taxonomy, decision taxonomy)
- `docs/templates/field-trial-report.md` (report skeleton)
- `docs/adr/0002-adopt-field-trial-methodology.md`
- `docs/field-trials/follow-ups.md` (deferred-finding index rules)

## Checklist

- [ ] Report filename matches `YYYY-MM-field-trial-N.md` with `N` monotonically increasing from the previous trial.
- [ ] The companion trial clone lives under `../NeNe-FT/ft{N}-{topic}/` and was created via `tools/nene-ft-new.sh {topic}` (which now sanity-checks FRAMEWORK_ROOT — PR #283).
- [ ] Report includes the standard sections: Date, Baseline, Goal, Service Built, Steps Taken, Results, Friction Summary, Recommendations, Overall Impression, Follow-up Issues, Reminder. Skeleton at `docs/templates/field-trial-report.md`.
- [ ] Baseline section pins the NeNe ref (commit SHA) the clone was based on and lists the previous trials' improvements that were verified.
- [ ] Every finding has a stable `F-N` identifier referenced both inline and in the Friction Summary table.
- [ ] Friction Summary rows include severity (`high` / `medium` / `low`), kind (`docs-gap` / `feature-gap` / `design-trade-off` / `legacy-preserved` / `process-gap`), and decision (`fix-in-framework` / `document` / `defer` / `keep-legacy`).
- [ ] Hypotheses (H-A, H-B, ...) recorded at trial start get an outcome line in the report — explicit "no, unfired" entries are useful signal too.
- [ ] Positive findings (things that worked first-try) are recorded with the same `F-N` shape (kind `n/a (positive)`, decision `no action`).
- [ ] Each non-deferred finding has a focused GitHub Issue filed and referenced from the "Follow-up Issues" section.
- [ ] Deferred findings (decision = `defer`) are appended to `docs/field-trials/follow-ups.md` with a "Re-evaluation trigger" line.
- [ ] When a deferred entry's trigger fires in a later trial, it is escalated to an Issue and removed from `follow-ups.md` in the same PR that introduces the new trial report (or its first follow-up PR).
- [ ] Trial Issue (e.g. `#274`) is closed by the report PR (`Closes #N` in commit / PR body).
- [ ] Trial clone settings (`.claude/settings.local.json` + `.claude/CLAUDE.md`) and `FT{N}-PLAN.md` are **not** committed back to the framework repo. Only the final report ships.
- [ ] PR body lists this checklist.
