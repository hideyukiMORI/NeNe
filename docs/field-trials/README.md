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

Setup for a new trial:

```sh
mkdir -p ../NeNe-FT
git clone git@github.com:hideyukiMORI/NeNe.git ../NeNe-FT/ft{N}-{topic}
cd ../NeNe-FT/ft{N}-{topic}
composer install
```

Each trial directory is independent. It is a real clone, so its own `.git`, `.env`, and `data/` live inside it. None of those artifacts are committed back into this framework repository.

When a trial uncovers framework or documentation changes, those changes are made in this repository (`hideyukiMORI/NeNe`) through the normal Issue-driven workflow, not inside the trial directory. The trial directory exists only for the duration of the trial.

## Naming and Numbering

- Trial reports live in this directory and are named `YYYY-MM-field-trial-{N}.md`, where `N` is monotonically increasing across all trials (no resets).
- Clone directories are named `ft{N}-{topic}` (lowercase, hyphenated topic). `N` matches the report number.
- Each trial gets exactly one report file. Follow-up work that traces back to a trial is tracked through GitHub Issues, not by editing the report after the fact.

## What to Record

A trial report should be readable cold by someone who was not present for the trial. The required sections live in `docs/templates/field-trial-report.md`. The important parts are:

- **Baseline**: which NeNe commit, tag, or release was cloned, PHP version, DB, and any other environmental facts that would change the result.
- **Goal**: one or two sentences on what this trial is verifying.
- **Steps Taken**: the actual flow of work, with `Finding (F-N)` notes embedded where friction occurred. Friction notes are the most valuable part of the report.
- **Friction Summary**: a single table with one row per `F-N`, including location, severity (high / medium / low), kind, and decision.
- **Recommendations**: grouped as immediate (documentation fix only), suggested (small framework or template change), and trade-off (changes that require an ADR or stakeholder discussion).
- **Overall Impression**: a short paragraph. Useful for orienting future readers; not a marketing summary.

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
2. Update `docs/todo/current.md` with a short "FT{N}" block linking the Issues.
3. Update `docs/roadmap.md` if the trial moved a phase forward.
4. Delete the trial clone under `../NeNe-FT/` when its work is done, unless it has independent value (for example as a future public sample).

The trial is finished when its Issues are merged or closed with a recorded rationale. A trial does not need to "succeed" — recording friction is the success condition.

## Templates and Tools

- Report skeleton: `docs/templates/field-trial-report.md`
- Workflow: `docs/workflow.md`
- Commit conventions: `docs/development/commit-conventions.md`
- AI agent entry point: `AGENTS.md`

## Index

No trials have been recorded yet. The first trial should be `docs/field-trials/YYYY-MM-field-trial-1.md`.
