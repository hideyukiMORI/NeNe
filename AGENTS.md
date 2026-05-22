# Agent / AI Guide

This file is the first document for AI agents and automation working on NeNe.

## Read First

- Project overview: `docs/project.md`
- Contributor guide: `docs/CONTRIBUTING.md`
- Workflow: `docs/workflow.md`
- Coding standards: `docs/development/coding-standards.md`
- Error codes & response decoration: `docs/development/error-codes.md` (envelope + the "error-path early-exit trap" — relevant whenever framework-wide response headers are added)
- Error rendering (REST vs HTML): `docs/development/error-rendering.md` (404 / 500 / domain failure / CSRF / auth / 405 — what the caller actually sees on each side)
- Production deployment: `docs/development/production-deployment.md` (env-var matrix, `compose.prod.yaml` overlay, log surface, Secure-cookie caveat, deployment checklist)
- Smarty custom plugins: `docs/development/smarty-plugins.md` (modifier / function / block authoring under `view/plugins/`)
- File uploads: `docs/development/file-uploads.md` (`Request::getFile` / `UploadedFile::validate` / `ControllerBase::sendFile` + storage convention + security summary)
- Email sending: `docs/development/email-sending.md` (`Nene\Xion\Mailer` + `MailMessage` + `NENE_MAIL_DSN` env + mailpit dev catcher; ADR-0006)
- Security headers / cross-cutting decoration: `docs/development/security-headers.md` (`Nene\Xion\ResponseDecorator` + `NENE_SECURITY_*` env; ADR-0007 — resolves the FT7 F-6 / FT8 F-4 decoration trap)
- Observability (request-id + future cross-cutting concerns): `docs/development/observability.md` (`Nene\Xion\RequestId` + `X-Request-ID` header + Monolog `extra.request_id` + recipe for future per-request decorations)
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
