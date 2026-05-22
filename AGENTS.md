# Agent / AI Guide

This file is the first document for AI agents and automation working on NeNe.

## Read First

- Project overview: `docs/project.md`
- Contributor guide: `docs/CONTRIBUTING.md`
- Workflow: `docs/workflow.md`
- Coding standards: `docs/development/coding-standards.md`
- Error codes & response decoration: `docs/development/error-codes.md` (envelope + the "error-path early-exit trap" — relevant whenever framework-wide response headers are added)
- AI self-review: `docs/ai/README.md`
- Commit conventions: `docs/development/commit-conventions.md`
- Roadmap: `docs/roadmap.md`
- Current TODO: `docs/todo/current.md`
- ADR index: `docs/adr/README.md`
- Field trial methodology: `docs/field-trials/README.md`
- MCP bridge (external package): [nene-mcp](https://github.com/hideyukiMORI/nene-mcp) — NeNe integration at `docs/integration/nene.md` in that repo; not part of NeNe core

## Operating Rules

- Work from GitHub Issues. If a code, documentation, or configuration change has no Issue, create one before editing.
- Do not commit directly to `main`. Use a topic branch named like `type/issue-number-summary`.
- Keep changes focused. Do not mix framework migration, feature work, dependency updates, and cosmetic cleanup in one PR.
- Keep milestones, roadmap, TODO, and ADRs aligned with Issues and PRs.
- Do not commit secrets, credentials, local `.env` files, generated logs, cache, or compiled templates.
- Prefer explicit, typed, testable PHP over hidden behavior.
- Preserve NeNe's small legacy framework character while gradually improving standards support.
- MCP stdio servers belong in [nene-mcp](https://github.com/hideyukiMORI/nene-mcp), not in `class/xion/`. Link OpenAPI operations via app-owned `docs/mcp/tools.json` when adding MCP-facing docs or examples.

## Project Direction

NeNe is a legacy, simple, lightweight PHP framework. It uses a front controller and URL segments to resolve controllers and action methods.

- Follow PSR basics where practical.
- Keep PHP, Composer packages, PHP CS Fixer, PHPDoc, security, and OpenAPI practices moving toward current stable standards.
- Document architectural decisions in ADRs before making broad framework changes.
