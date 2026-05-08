# Roadmap

This roadmap describes NeNe's current direction. Each item should become GitHub Issues before implementation.

## 0. Legacy Framework Stabilization

- Keep the legacy directory structure and URL-based routing rules.
- Resolve known security issues.
- Make clone-based setup simple, fast, and stable.
- Add Docker-based local development.
- Finish dispatcher behavior for correct REST handling.
- Complete OpenAPI introduction.
- Keep NeNe as an old-school, simple, lightweight framework.
- Improve onboarding docs, ADRs, and implementation support.

## 1. Documentation Foundation

- Add AI and contributor guidance.
- Document routing, controller conventions, and current architecture.
- Establish coding standards, workflow, roadmap, TODO, milestone, and ADR practices.

## 2. PHP Modernization

- Keep Composer dependencies current and remove unused packages.
- Gradually improve PHPDoc and native type declarations.
- Make PHP CS Fixer and PSR-12 usage repeatable.
- Establish a static analysis baseline for Phan.

## 3. Security Hardening

- Triage Dependabot alerts quickly.
- Review request input handling, output escaping, session handling, and error responses.
- Document secure defaults for controllers, REST responses, templates, and logs.

## 4. OpenAPI and API Contracts

- Define where OpenAPI files live.
- Document REST method conventions.
- Add OpenAPI contracts for public JSON endpoints.

## 5. Framework Architecture

- Document current routing and lifecycle.
- Decide which legacy conventions should remain stable.
- Use ADRs before major changes to routing, configuration, dependency policy, or API boundaries.
