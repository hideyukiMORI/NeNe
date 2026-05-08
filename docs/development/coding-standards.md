# Coding Standards

NeNe is a legacy PHP framework, but new work should move the codebase toward current stable PHP and common PHP standards.

## PHP Compatibility

- Support the latest stable PHP version as far as practical.
- Keep compatibility decisions explicit in Issues and ADRs.
- Avoid new code that depends on deprecated PHP behavior.
- Prefer strict types for new PHP files.
- Use typed parameters, return types, and properties when they improve clarity and do not break compatibility.

## Composer Packages

- Keep direct Composer dependencies on current stable versions where practical.
- Remove unused packages instead of upgrading them.
- Use `composer update vendor/package --with-dependencies` for targeted dependency work when possible.
- Do not commit `vendor/`.
- Run `composer validate --strict` after dependency or Composer metadata changes.
- Run `composer install --dry-run` to confirm lock file consistency.

## PSR and Style

- Follow PSR-4 autoloading.
- Follow PSR-12 coding style.
- Use PHP CS Fixer as the primary formatter when formatting is needed.
- Do not mix formatting-only changes into unrelated PRs.
- Preserve the existing namespace layout unless an Issue and ADR approve a migration.

## PHPDoc

PHPDoc should be useful and accurate.

- Document public and protected classes, methods, and properties.
- Keep `@param`, `@return`, and `@throws` accurate.
- Prefer native PHP types when possible, with PHPDoc used for richer array shapes or domain meaning.
- Do not leave placeholder annotations such as `[type]`, `mixed` without context, or stale class names.
- Update PHPDoc when changing method behavior or return values.

## Security

Security fixes should be small, focused, and prioritized.

- Treat Dependabot alerts and known CVEs as high-priority maintenance work.
- Validate and sanitize input at the boundary.
- Escape output by default in templates.
- Avoid exposing stack traces, secrets, or local paths in production responses.
- Do not trust request headers or URL parameters.
- Avoid dynamic class, method, file, or template resolution unless constrained by framework conventions.
- Keep sessions, authentication, and authorization behavior explicit.
- Never commit secrets or local environment files.

## OpenAPI

New public HTTP APIs should be documented with OpenAPI.

- REST-style controller methods should have an OpenAPI contract before or alongside implementation.
- Keep request and response schemas explicit.
- Prefer JSON responses for API endpoints.
- Keep OpenAPI files in `docs/api/` unless a future ADR chooses another location.

## Testing and Static Analysis

- At minimum, run PHP syntax checks for changed PHP files.
- Use Composer validation for dependency and package metadata changes.
- Use Phan where practical. If existing baseline issues block adoption, record the limitation in the PR and create follow-up Issues.
- Add focused tests when adding behavior that can be tested without large framework setup.

## Legacy Compatibility

NeNe has legacy conventions and global configuration constants. Do not rewrite them opportunistically.

When improving old code:

- Keep behavior compatible unless the Issue explicitly asks for a breaking change.
- Prefer small migrations over broad rewrites.
- Use ADRs for compatibility policy changes.
