# Release and CI Self-Review

Use this checklist for changes to `.github/workflows/`, branch protection, auto-merge config, dependency upgrades, or version tagging.

Source policies:

- `.github/workflows/tests.yml`
- `docs/development/docker.md` (CI-relevant env vars)
- `docs/development/commit-conventions.md`

## Checklist

- [ ] CI changes start from a documented local command before becoming required (e.g. `composer test`, `composer test:http`).
- [ ] The `unit` and `HTTP runtime smoke (Docker)` jobs both pass on this PR before merge.
- [ ] Required status checks on `main` branch protection match the actual job names; renaming a job in `.github/workflows/tests.yml` requires updating the branch protection rule via `gh api repos/.../branches/main/protection`.
- [ ] The "Wait for /health" step uses `healthStatus=ok` (not just HTTP 200) as the readiness signal (PR #259).
- [ ] Health-wait timeout is at least 90s for the cold-start path (PR #288). Reducing the budget requires verifying tail-end runner behavior.
- [ ] When CI fails on "Wait for /health", container logs were inspected for PHP fatals or missing dependencies **before** assuming a timing issue (see `feedback-ci-debugging-discipline` memory: an old `Constant expression contains invalid operations` masqueraded as a timeout in PR #284).
- [ ] Dependency upgrades touch `composer.json` and `composer.lock` in the same commit; the lockfile is committed.
- [ ] Branch protection on `main` continues to require status checks to pass (`gh api repos/.../branches/main/protection` confirms required contexts).
- [ ] Auto-merge (`gh pr merge --auto --merge --delete-branch`) is used for routine improvement-loop PRs; never set `--no-verify` or `--force` on shared branches.
- [ ] `.github/workflows/` YAML parses (e.g. via `actionlint` if locally available); secrets and tokens are not hardcoded.
- [ ] PR body lists this checklist when workflow or CI infra changes.
