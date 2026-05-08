# Contributing

NeNe development is Issue driven. Every meaningful code, documentation, dependency, or configuration change should start from a GitHub Issue.

## Basic Flow

1. Create or select a GitHub Issue.
2. Create a branch from `master`.
3. Make a focused change.
4. Run the relevant checks.
5. Commit with the repository commit convention.
6. Push and open a pull request.
7. Merge after review or verification.
8. Keep the Issue, milestone, TODO, roadmap, and ADRs aligned.

## Branch Names

Use:

```text
type/issue-number-summary
```

Examples:

- `docs/6-project-guides`
- `fix/12-routing-404`
- `chore/15-dependency-upgrades`
- `feat/20-openapi-export`

## Scope Control

Keep each PR small enough to review.

Avoid mixing:

- Framework architecture changes.
- Dependency upgrades.
- Security fixes.
- Feature work.
- Formatting-only cleanup.
- Documentation rewrites.

If a change uncovers a separate issue, create a follow-up Issue instead of expanding the PR.

## Safety

Do not commit:

- Secrets, tokens, passwords, private keys, or credentials.
- Local `.env` files.
- Generated logs.
- Composer `vendor/`.
- Smarty compiled templates.
- Temporary files, cache, or local tool output.

## Documentation

Update documentation when behavior, conventions, workflow, or architecture changes.

Use ADRs for decisions that affect architecture, compatibility, framework direction, routing, API contracts, or long-term maintenance.
