# Development Workflow

NeNe uses an Issue driven workflow.

## Required Flow

1. Create or confirm a GitHub Issue.
2. Assign a milestone when the work belongs to a planned release or theme.
3. Create a topic branch from `main`.
4. Implement only the Issue scope.
5. Run checks and record the results in the PR.
6. Walk the relevant self-review checklists under `docs/review/` (see below).
7. Commit using the commit convention.
8. Push and open a PR.
9. Reference the Issue from the PR body.
10. Merge after review or verification.
11. Close or update the Issue.

## Self-Review Checklists

Before pushing, walk the checklists in `docs/review/` that match the scope of the change. The list at `docs/review/README.md` maps PR types to checklists (REST controller, HTML controller, database, OpenAPI, docs/ADR, release/CI, field trial report). A single PR may invoke more than one. Reference the checklist(s) you used in the PR body.

Checklists are reminders to consult the source policy docs — they do not duplicate policy text. If a checklist item conflicts with the canonical policy, fix the checklist (or escalate via ADR).

## Pull Request Requirements

Each PR should include:

- Purpose.
- Summary of changes.
- Verification results.
- Related Issue, preferably with `Closes #number`.
- Notes about known limitations or follow-up work.

## Milestones

Use milestones to group work by release, modernization stage, or maintenance theme.

Examples:

- `legacy-framework-stabilization`
- `docs-foundation`
- `php-modernization`
- `security-hardening`
- `openapi-contracts`

Keep `docs/milestones/` aligned with GitHub milestones when a milestone becomes important enough to track locally.

## TODO Management

Use `docs/todo/current.md` for short-term work visible to humans and AI agents.

The TODO file should not replace GitHub Issues. It should summarize active or next work and link to Issues where possible.

## Roadmap Management

Use `docs/roadmap.md` for medium-term direction.

The roadmap should describe intent and sequencing. Implementation details belong in Issues, PRs, and ADRs.

## ADR Management

Use `docs/adr/` for architectural decisions.

Create an ADR when a change:

- Alters routing, controller conventions, or API behavior.
- Adds or removes major dependencies.
- Changes compatibility policy.
- Introduces OpenAPI contracts.
- Changes security posture.
- Affects long-term framework direction.
