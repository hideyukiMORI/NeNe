# Self-Review Checklists

Use these checklists as the last step before opening a PR. Each list points at the policy docs that own the rules; the checklists do not duplicate policy text, they remind you to check it.

The format is borrowed from the sibling project NENE2's `docs/review/` and adapted to NeNe's own framework shape (session + CSRF auth, Smarty HTML, MySQL/SQLite parity, OpenAPI generic envelope, field-trial methodology).

## When to use which

| Checklist | Use for |
| --- | --- |
| [`rest-controller.md`](rest-controller.md) | New or modified REST handlers (`indexGetRest`, `indexPostRest`, etc.), API error catalog updates, REST auth/CSRF changes. |
| [`html-controller.md`](html-controller.md) | New or modified HTML pages (`actionAction`), Smarty templates, asset auto-discovery files, form POST handling. |
| [`database.md`](database.md) | Schema additions, mapper changes, MySQL/SQLite parity edits, transaction boundaries. |
| [`openapi-contract.md`](openapi-contract.md) | Anything that touches `docs/api/openapi.yaml` or the contract test. |
| [`docs-and-adr.md`](docs-and-adr.md) | Policy doc edits, ADR additions, roadmap / milestones / TODO changes. |
| [`release-ci.md`](release-ci.md) | `.github/workflows/`, branch protection, auto-merge config, dependency upgrades. |
| [`field-trial-report.md`](field-trial-report.md) | A new `docs/field-trials/YYYY-MM-field-trial-N.md` or its follow-up Issues. |

A single PR may invoke more than one checklist (a REST endpoint usually triggers `rest-controller.md` + `openapi-contract.md` + `database.md`). Reference the checklist(s) you used in the PR body.

## How to use them

1. Open the relevant checklist before pushing the PR.
2. Walk every item. If an item does not apply, say so in the PR body — silence reads as "I didn't check".
3. If a checklist item conflicts with the source policy, fix the checklist (or escalate via ADR) — checklists are reminders, never a second source of truth.

## Policy references

The checklists link back to the canonical policy docs:

- `docs/workflow.md` — Issue / branch / PR lifecycle
- `docs/CONTRIBUTING.md` — scope control, branch naming, safety
- `docs/development/coding-standards.md` — code-level conventions
- `docs/development/commit-conventions.md` — Conventional Commits style
- `docs/development/testing.md` — unit + HTTP runtime smoke tests
- `docs/development/docker.md` — local environment + env vars
- `docs/development/error-codes.md` — error catalog (post ADR-0003)
- `docs/api/README.md` — OpenAPI policy
- `docs/api/reference-client.md` — external consumer mechanics
- `docs/tutorials/building-a-service.md` — full tutorial flow
- `docs/field-trials/README.md` — FT methodology (ADR-0002)
- `docs/adr/` — major decisions (0001–0004 currently)

If a checklist item refers to "the policy" without a link, that is a bug; file an Issue.
