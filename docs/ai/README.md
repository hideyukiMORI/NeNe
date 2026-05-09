# AI-Assisted Development

This directory gives AI agents and human reviewers a compact checklist for NeNe changes.

NeNe should stay small, explicit, and reviewable. AI-assisted code is acceptable only when it follows the same visible conventions that a human NeNe developer would normally use.

## Read Before Implementing

- `../project.md`: project shape, routing convention, and framework/application boundaries.
- `../development/coding-standards.md`: coding standards and responsibility boundaries.
- `../tutorials/building-a-service.md`: standard path for pages, REST endpoints, mapper work, OpenAPI, and tests.

## Self-Review Checklists

- `self-review/service-implementation.md`: full feature checklist for small-service changes.
- `self-review/controller.md`: controller size and HTTP boundary checklist.
- `self-review/database.md`: mapper, model, SQL, and transaction checklist.

Use these checklists before opening a PR. If a change intentionally does not follow one of these rules, explain the reason in the Issue or PR.
