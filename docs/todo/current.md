# Current TODO

This file summarizes short-term work for humans and AI agents. GitHub Issues remain the source of truth for actionable work.

## Active

- #177: Prepare the Zenn renovation-story article.

## Recently Completed

- #176: Prepare Composer and Packagist-facing metadata for public discovery while keeping `git clone` as the recommended install path.
- #174: Clarify that `git clone` is the recommended install path for now.
- #172: Clarify that the review-cost message is about implementation-style variance, not outside reviewer scarcity.
- #164: Update the public entry to present NeNe as a reviewable small-service PHP framework.
- #169: Position the publication strategy document as a public OSS release case study.
- #167: Add the review-cost angle around modern pattern learning and implementation-style variance.
- #163: Reframe the next phase around reducing code review cost through consistent implementation conventions.
- #162: Document the publication and outreach strategy for NeNe after `v0.2.0`.
- #160: Document AI self-review checklists and service-layer implementation standards.
- #158: Prepare the `v0.2.0` release notes and runtime version.
- #154, #155, #156: Clean up release-blocking code quality concerns in `ControllerBase` and `DataMapperBase`.
- #152: Add the canonical transaction pattern to the sample page tutorial.
- #150: Document the canonical transaction pattern for service tutorials and coding standards.
- #148: Add a database transaction boundary for multi-step mapper work.
- #135: Refactor `ControllerBase` responsibilities into a testable CSRF protection boundary.
- #144: Adjust Phase 6 around reference implementations and small-service delivery.
- #142: Document AI readability and small-service delivery as the next project phase.
- #140: Clarify Docker development database credentials for phpMyAdmin and MySQL.
- #138: Add phpMyAdmin with the darkwolf theme to the Docker development environment.
- #136: Change the project license to MIT.
- #133: Document the `v0.1.0` release milestone and prepare the first framework tag.
- #131: Show the runtime environment label in the development health check card.
- #129: Show the database type in the development health check card.
- #127: Remove the tracked generated SQLite database artifact.
- #125: Clarify the SQLite3 initialization command in install documentation.
- #123: Load the repository-root `.env` before web runtime initialization.
- #121: Add server install database setup CLI and runtime health check.
- #120: Add a traditional Apache/PHP server install guide and public documentation page.
- #116: Expand HTTP runtime coverage for explicit routing, REST method boundaries, and JSON-only responses.
- #108: Remove legacy JSONP output and move JSON handling to the response boundary.
- #106: Update roadmap and milestones to reflect current status and architecture policy.
- #104: Clarify NeNe's renovation philosophy and target audience.
- #102: Add a service implementation tutorial for pages, REST endpoints, database-backed features, OpenAPI, and tests.
- #99: Prepare documentation/sample sections for the home side menu: `Authentication`, `Routing Guide`, and `OpenAPI`.
- #98: Refresh TODO from roadmap, milestones, and Issue state.
- #88: Fix request variable storage and add boundary tests.
- #87: Decide and apply non-200 HTTP status policy for authentication failures.
- #86: Route Dispatcher errors through the shared JSON error responder.
- #85: Safely encode template data-object JSON for inline script output.
- #84: Harden legacy callback output before the JSON-only policy superseded it.
- #83: Add CSRF protection to cookie-authenticated state-changing APIs.
- #82: Hash stored passwords and clean up sample credentials.
- #81: Harden authentication session lifecycle and cookie attributes.
- #80: Control public error display by environment.
- #78: Parse OpenAPI runtime contract tests with `symfony/yaml`.
- #76: Add PHP CS Fixer configuration and formatting scripts.
- #74: Add Phan baseline and repeatable static analysis configuration.
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

- #178: Prepare the Qiita hands-on implementation tutorial article.
- #179: Prepare the DEV Community English introduction article.
- #180: Decide on Reddit/Hacker News only after the first article feedback.
- #165: Prove the reviewable Controller-Service-Mapper shape through the reference implementation.
- #145: Add an AI-assisted small-service reference implementation covering page, REST, mapper, OpenAPI, and tests.

## Backlog Candidates

- Improve PHPDoc accuracy and native types across `class/xion/`, starting with shared base classes.
- Extract dispatcher route parsing and method resolution boundaries further if future tests need lower-level coverage.
- Decide PHP minimum and target version policy in an ADR if support outside Docker PHP 8.4 becomes important.
- Add CI coverage for Docker runtime checks when repository resources and runtime cost are acceptable.
- Review GitHub Actions runtime deprecation warnings and update workflow actions when Node.js 24-ready versions are available.
