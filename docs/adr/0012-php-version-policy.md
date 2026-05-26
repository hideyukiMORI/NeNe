# ADR-0012 — PHP version policy

Status: **accepted** (2026-05-27)

## Context

NeNe's `Dockerfile` pins `FROM php:8.4-apache`. The `composer.json` has no explicit `"php": ">=X.Y"` constraint in the `require` section. The framework uses PHP 8.4+ syntax throughout (`readonly` properties, `match` expressions, named arguments, `enum`, intersection types, first-class callables) but this dependency is implicit rather than declared.

PHP's release schedule:

| Version | GA | Active support ends | Security support ends |
| --- | --- | --- | --- |
| 8.3 | 2023-11 | 2024-11 | 2026-11 |
| 8.4 | 2024-11 | 2025-11 | 2027-11 |
| 8.5 | ~2025-11 expected | ~2026-11 | ~2028-11 |

As of 2026-05-27, PHP 8.4 is in active support. PHP 8.5 development is underway.

Without a documented policy, every session that bumps the PHP version or adds a dependency that requires a newer PHP makes a silent architectural decision.

## Decision

### 1. Declare the minimum PHP version in `composer.json`

Add `"php": ">=8.4"` to the `require` section. This makes the constraint explicit, allows `composer require` to warn when a dependency needs a newer minimum, and communicates the supported floor to deployers.

This ADR records the decision; the mechanical change is tracked in issue #441 and the corresponding PR.

### 2. PHP version upgrade cadence

**Minor releases (8.4.x → 8.4.y)**: Accept automatically. Minor releases are security / bug fixes; no code changes required. The `FROM php:8.4-apache` Docker image tag floats to the latest 8.4.x automatically.

**Minor-version bumps (8.4 → 8.5)**:

Upgrade when **all three** conditions are met:

1. The new minor version has been GA for at least **3 months** (gives the ecosystem time to catch up — popular packages ship compatible releases, Ubuntu/Debian LTS base images appear, etc.).
2. The two most widely-used NeNe deployers can migrate without a block (check `composer.lock` for packages that pin a maximum PHP).
3. A concrete NeNe user or deployer needs a 8.5+ feature, **or** 8.4's security support window is within 6 months of ending.

**Do not** bump the minimum PHP version preemptively for "modernity" alone — the upgrade cost flows to every deployer. The 8.5 bump date is therefore undecided and will be logged in `docs/todo/current.md` as a future decision point when 8.5 GA is announced.

**Major-version bumps (8.x → 9.0)**:

PHP 9.0 has no announced timeline. When it is announced, file an Issue and ADR to address:

- Removed functions / changed behavior (PHP 9.0 is expected to drop some legacy polyfills).
- Updated `Dockerfile` base image.
- Updated `composer.json` minimum.
- Deployer migration guidance.

### 3. What triggers an immediate review outside the cadence

- A `composer require` warns about minimum-php mismatch.
- Phan / phpunit drops support for the current minimum.
- A CVE is discovered in a PHP 8.4-specific subsystem with no 8.4.x patch available.

### 4. Composer `platform` override policy

Some NeNe CI setups run `composer install` on a host with a different PHP version than the container. If the declared minimum causes platform conflicts, use `"platform": {"php": "8.4.0"}` in `composer.json` (the `config.platform` section) rather than loosening the `require` constraint.

## Consequences

- **Positive**: deployers and dependency resolution tools can now verify compatibility against the declared minimum.
- **Positive**: `composer require <package>` will warn if the package requires PHP 8.5+ before the framework is ready to commit to that.
- **Positive**: the upgrade cadence removes ambiguity from future PHP bump decisions.
- **Neutral**: the `Dockerfile` `FROM php:8.4-apache` tag must be bumped manually when the policy triggers a minimum bump. No automation is added by this ADR.
- **Negative**: a declared `>=8.4` minimum means deployers running PHP 8.3 will see a `composer install` error instead of a silent mismatch. This is intentional — PHP 8.3 active support ended 2024-11 and security support ends 2026-11. Deployers still on 8.3 should upgrade.

## Related

- `Dockerfile` — `FROM php:8.4-apache` (the Docker runtime constraint).
- `composer.json` — `"require": {"php": ">=8.4"}` (added in the PR for #441).
- `docs/development/production-deployment.md` — runtime environment documentation.
- `docs/field-trials/candidates.md` — this ADR closes the `PHP version policy ADR` candidate.
