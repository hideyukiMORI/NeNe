# Development Workflow

NeNe uses an Issue driven workflow.

## Required Flow

1. Create or confirm a GitHub Issue.
2. Assign a milestone when the work belongs to a planned release or theme.
3. Create a topic branch from `master`.
4. Implement only the Issue scope.
5. Run checks and record the results in the PR.
6. Commit using the commit convention.
7. Push and open a PR.
8. Reference the Issue from the PR body.
9. Merge after review or verification.
10. Close or update the Issue.

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
