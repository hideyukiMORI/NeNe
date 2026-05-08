# Current TODO

This file summarizes short-term work for humans and AI agents. GitHub Issues remain the source of truth for actionable work.

## Active

- #72: Refresh this TODO from the current roadmap, milestones, and Issue state.

## Recently Completed

- #70: Document the OpenAPI runtime contract test parser policy.
- #68: Update the SQLite initializer so the TODO sample also works with SQLite fallback.
- #66: Expand first-reader comments in `ini/xSystemIni.php`.
- #64: Organize `ini/xSystemIni.php` constants, runtime definitions, ordering, and comments.
- #62: Remove unused legacy styles from `htdocs/css/common.css`.
- #60: Simplify `view/source` for the React sample layout.
- #58: Remove unused Vue-era assets and templates.
- #56: Extend HTTP runtime tests and CI-oriented checks.
- #54: Add HTTP runtime smoke tests.
- #52: Polish Swagger UI with a consistent dark theme.
- #50: Add starter OpenAPI contract and Swagger UI.
- #16: Add PHPUnit test foundation and first pure function tests.
- #8: Rename default branch from `master` to `main`.
- #6: Add project documentation, AI guide, coding standards, workflow, roadmap, TODO, milestones, and ADR foundation.

## Next

- Create a Phan baseline/configuration so static analysis becomes repeatable for current PHP 8.4-targeted code.
- Add PHP CS Fixer setup and document the exact formatting command used for PSR-12-oriented cleanup.
- Review security-sensitive request/session/template/error-handling paths and record focused hardening Issues.
- Document real routing examples for HTML actions and method-specific REST actions.
- Prepare documentation/sample pages for the home side menu: `Authentication`, `Routing Guide`, and `OpenAPI`.
- Add a follow-up Issue to migrate `OpenApiRuntimeContractTest` from line-based parsing to `symfony/yaml` before expanding OpenAPI assertions.

## Backlog Candidates

- Improve PHPDoc accuracy and native types across `class/xion/`, starting with shared base classes.
- Extract dispatcher route parsing and method resolution boundaries further if future tests need lower-level coverage.
- Decide PHP minimum and target version policy in an ADR if support outside Docker PHP 8.4 becomes important.
- Add CI coverage for Docker runtime checks when repository resources and runtime cost are acceptable.
- Review GitHub Actions runtime deprecation warnings and update workflow actions when Node.js 24-ready versions are available.
