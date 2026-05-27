# Field Trials

A Field Trial (FT) is a small, time-boxed exercise where NeNe is used from a fresh clone to build a tiny realistic service. Every point of confusion or friction encountered during the trial is recorded with a stable identifier (`F-1`, `F-2`, ...) and converted into GitHub Issues that drive framework and documentation changes.

The goal is not to ship the trial service. The goal is to surface what NeNe assumes but does not document, what its conventions actually feel like for a new project, and which legacy shapes need a clearer explanation rather than a redesign.

## When to Run a Field Trial

Run a new FT when one or more of the following is true:

- A meaningful release or modernization step landed and external usability should be re-checked.
- A documentation set was rewritten and should be verified by following it end to end.
- A new convention was introduced (for example a new REST handler shape or template directory) and its real onboarding cost is unknown.
- An ADR or roadmap phase explicitly schedules a trial.

A single short trial is more valuable than a large list of speculative improvements. Keep each trial focused on one realistic small service.

## Clone Layout

All NeNe field trials are run from a fresh `git clone` placed under the `../NeNe-FT/` directory next to this repository. The directory structure is:

```text
~/github/
├── NeNe/                  # this repository (framework)
└── NeNe-FT/
    ├── ft1-{topic}/       # FT1 clone (independent working tree)
    ├── ft2-{topic}/       # FT2 clone
    └── ...
```

Setup for a new trial — one-shot helper:

```sh
tools/nene-ft-new.sh {topic-kebab}
```

This auto-detects the next `N`, clones from the local framework HEAD into `../NeNe-FT/ft{N}-{topic}/`, sets host port offsets (`app=8080+N`, `mysql=3307+N`) so the clone can run alongside the framework, drops a `.claude/settings.local.json` with the FT autonomy permissions, and writes an `FT{N}-PLAN.md` skeleton. The script prints the next steps (`docker compose up`, `/health` probe, Issue filing).

Manual equivalent, if the helper is not available:

```sh
mkdir -p ../NeNe-FT
git clone git@github.com:hideyukiMORI/NeNe.git ../NeNe-FT/ft{N}-{topic}
cd ../NeNe-FT/ft{N}-{topic}
composer install
```

Either path produces an independent trial directory. It is a real clone, so its own `.git`, `.env`, and `data/` live inside it. None of those artifacts are committed back into this framework repository.

When a trial uncovers framework or documentation changes, those changes are made in this repository (`hideyukiMORI/NeNe`) through the normal Issue-driven workflow, not inside the trial directory. The trial directory exists only for the duration of the trial.

## Naming and Numbering

- Trial reports live in this directory and are named `YYYY-MM-field-trial-{N}.md`, where `N` is monotonically increasing across all trials (no resets).
- Clone directories are named `ft{N}-{topic}` (lowercase, hyphenated topic). `N` matches the report number.
- Each trial gets exactly one report file. Follow-up work that traces back to a trial is tracked through GitHub Issues, not by editing the report after the fact.

## Report Requirement — Every FT Must Have a Report File

**Every completed FT must have a report file in `docs/field-trials/`.** The archive trail entry in `candidates.md` is a one-line index pointer, not a substitute for the report. An FT without a report file is not considered finished.

Two formats are recognized. Choose based on trial type.

### Format A — Full exploratory report

Use when: the trial is open-ended, friction was encountered, an ADR was triggered, or the outcome was unexpected.

Template: `docs/templates/field-trial-report.md`  
Create with: `tools/ft-report-new.sh FT{N} {topic}`

Required sections:

- **Baseline**: NeNe commit/ref, PHP version, DB, environment facts that affect reproducibility.
- **Goal**: one or two sentences on what this trial is verifying.
- **Steps Taken**: the actual flow of work with `Finding (F-N)` notes embedded where friction occurred.
- **Friction Summary**: one row per `F-N` — location, severity, kind, decision.
- **Recommendations**: immediate / suggested / trade-off.
- **Overall Impression**: one short paragraph.

### Format B — Xion class report (lightweight)

Use when: the trial adds one Xion helper class with no unexpected friction, no ADR trigger, and no open follow-up Issues.

Template: `docs/templates/field-trial-report-xion.md`  
Create with: `tools/ft-report-new.sh FT{N} {ClassName}`

Required sections:

- **Date / Branch / Baseline** (header block, three lines).
- **Goal**: one sentence.
- **What was built**: class name, public API table (method → description), key design points, test list.
- **Findings**: `F-1 — No finding (clean trial)` if clean; otherwise one row per finding with kind and decision.
- **Decision**: one line — merge as-is, or list follow-up Issues.

Minimum length: ~50 lines. A report shorter than that is missing something.

### Historical note

FT1–FT75 used Format A (full reports, 60–225 lines each).  
FT76–FT264 were completed with archive trail entries only — **this was a process gap**, not an approved exception. Those 189 trials are documented only at the archive-trail level. Future trials must not repeat this pattern.  
FT265 onward: Format B minimum, Format A when warranted.

### Friction Kinds

Every `F-N` row should be tagged with one kind:

| Kind | Meaning |
| --- | --- |
| `docs-gap` | The behavior is correct, but it is not documented or hard to find. |
| `feature-gap` | A small extension or helper would noticeably reduce setup cost. |
| `design-trade-off` | Behavior is intentional; the cost is worth flagging but should not change. |
| `legacy-preserved` | A legacy shape that NeNe intentionally keeps. The fix is documentation, never a redesign. |
| `process-gap` | The workflow, checklist, or tooling around the change is missing or unclear. |

`legacy-preserved` is the kind that makes NeNe trials different from a generic framework usability study. NeNe is a renovation project, and many legacy shapes are kept on purpose. When a trial uncovers one, the right response is to document why it stays, not to redesign it.

### Decisions

Every `F-N` row should also carry a Decision:

| Decision | Meaning |
| --- | --- |
| `fix-in-framework` | Change framework code or scripts. |
| `document` | Add or improve documentation only. |
| `keep-legacy` | No framework change; record the rationale. Often paired with a small documentation note. |
| `defer` | Real friction, but not worth acting on yet. Recorded so it is not lost. |

If a trial cannot recommend a Decision yet, mark the row `defer` and explain why in the recommendation section.

## What Not to Record

A field trial report is committed to a public repository. Do not include:

- Secrets, tokens, API keys, or session cookies.
- Local `.env` contents or credentials.
- Production hostnames, internal URLs, customer data, or stack traces that leak private paths.
- Confidential prompt text from a private collaboration.
- Anything that violates the safety rules in `docs/CONTRIBUTING.md`.

When in doubt, omit. A trial report is more valuable as a small, safe record than as a complete dump.

## Aftermath

After a trial:

1. Open one GitHub Issue per actionable finding. Reference the report file and the `F-N` row.
2. Append `defer` and unfiled `legacy-preserved` findings to `docs/field-trials/follow-ups.md` so they remain searchable when a later trial revisits the same surface.
3. Update `docs/todo/current.md` with a short "FT{N}" block linking the Issues.
4. Update `docs/roadmap.md` if the trial moved a phase forward.
5. Delete the trial clone under `../NeNe-FT/` when its work is done, unless it has independent value (for example as a future public sample).

The trial is finished when its Issues are merged or closed with a recorded rationale. A trial does not need to "succeed" — recording friction is the success condition.

### Cross-repo Issue reuse

When a sister project (NENE2, nene-mcp, nene2-python) opens an Issue against NeNe with concrete acceptance criteria — e.g. "with `NENE_*` set, this curl should return 200 — and the sister will run a confirmation FT once it merges" — **use that Issue directly as the trial Issue**. Do not open a new one.

Rationale:

- The cross-repo trace stays clean: the sister's FT report links to the same Issue NeNe's PR closes, so reviewers in either project see one timeline.
- The acceptance criteria are already authoritative; restating them in a new Issue invites drift between the two versions.
- The sister's confirmation FT is gated on that exact Issue closing, so referencing it in the trial report's "Trial Issue" field is unambiguous.

Examples in this repo's history:

- FT16 (`docs/field-trials/2026-05-field-trial-16.md`) reused Issue #380 (cross-repo handoff from nene-mcp FT204 / FT215 / FT225–FT419). The PR body's `Closes #380` triggered nene-mcp's confirmation FT.

When the trial closes, comment on the original Issue with a short status update so the sister-side reader sees the outcome without having to dig into PR descriptions.

## Templates and Tools

- Report skeleton: `docs/templates/field-trial-report.md`
- Workflow: `docs/workflow.md`
- Commit conventions: `docs/development/commit-conventions.md`
- AI agent entry point: `AGENTS.md`

## Index

| Trial | Date | Topic | Report |
| --- | --- | --- | --- |
| FT1 | 2026-05-20 | Baseline trial (from `bookmarklog` clone). Pivoted from Bookmark+Tag implementation when baseline findings filled the trial. Closed 5 Issues (#222–#227) including a `main`-fatal hotfix and a new CI runtime smoke job. | [`2026-05-field-trial-1.md`](2026-05-field-trial-1.md) |
| FT2 | 2026-05-21 | Bookmark + Tag CRUD against post-FT1 baseline. Two-entity M:N service with transactional relation diff, dual DB setup, OpenAPI extension, 6 new HTTP tests. 7 findings, 4 filed as Issues for follow-up. | [`2026-05-field-trial-2.md`](2026-05-field-trial-2.md) |
