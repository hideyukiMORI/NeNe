# NeNe Documentation

This directory contains project documentation for humans and AI agents.

## Start Here

- `project.md`: Project overview and routing convention.
- `CONTRIBUTING.md`: Contribution rules.
- `workflow.md`: Issue-driven workflow.
- `development/coding-standards.md`: Coding standards.
- `development/commit-conventions.md`: Commit message rules.
- `development/docker.md`: Docker local development.
- `deployment/server-install.md`: Traditional Apache/PHP server installation after `git clone`.
- `frontend/assets.md`: Smarty template, CSS, and JavaScript placement conventions.
- `development/testing.md`: Testing strategy and commands.
- `tutorials/building-a-service.md`: Practical guide for adding pages, REST endpoints, database-backed features, OpenAPI, and tests.
- `ai/README.md`: AI-assisted implementation and self-review checklists.
- `roadmap.md`: Project direction.
- `releases.md`: Human-readable release notes and version checkpoints.
- `publication-strategy.md`: Public release strategy and OSS publication case-study notes.
- `articles/zenn-renovating-legacy-php-framework.md`: Published Zenn article about renovating NeNe for modern PHP.
- `todo/current.md`: Current TODO summary.
- `milestones/README.md`: Milestone management.
- `adr/README.md`: Architecture decision records.
- `api/README.md`: OpenAPI and API documentation policy.
- `field-trials/README.md`: Field trial methodology and clone-based trial layout under `../NeNe-FT/`.
- `templates/field-trial-report.md`: Report skeleton copied for each new trial.

## Related Packages

NeNe keeps MCP out of framework core. For local stdio MCP over documented HTTP APIs, use the sibling Composer package **[nene-mcp](https://github.com/hideyukiMORI/nene-mcp)**:

- NeNe integration: [nene-mcp/docs/integration/nene.md](https://github.com/hideyukiMORI/nene-mcp/blob/main/docs/integration/nene.md)
- Project overview: [nene-mcp/docs/project.md](https://github.com/hideyukiMORI/nene-mcp/blob/main/docs/project.md)

## Source of Truth

GitHub Issues and PRs are the source of truth for active work.

Docs provide durable project context and should be updated when conventions, workflow, or architecture change.
