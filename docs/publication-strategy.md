# Publication Strategy

This document records how NeNe should be introduced after the `v0.2.0` release.

NeNe should not be presented as another general-purpose full-stack framework. Its strongest message is narrower and clearer:

> NeNe is a tiny renovated legacy-style PHP framework for readable small services. It keeps visible URL routing, controller actions, Smarty pages, lightweight mappers, REST endpoints, OpenAPI, Docker, tests, and explicit security boundaries.

The public angle should speak to a common developer pain:

> Have you ever lost time in code review just trying to understand the writer's style? NeNe aims to lower that cost by keeping small-service changes in the same visible shape, whether they are written by a person or assisted by an AI tool.

Another useful angle:

> Modern design patterns and fashionable coding styles can be powerful. But when only a few people have learned those patterns deeply enough to review them, a project can slow down because reviewers become scarce. NeNe favors visible, repeatable conventions so more developers can participate in review without first learning a large framework-specific style.

## Current Public State

NeNe already has enough material for a first public introduction:

- MIT license.
- GitHub Releases for `v0.1.0` and `v0.2.0`.
- Public sample deployment at `https://nene-php.com/`.
- Docker local development with app, MySQL, phpMyAdmin, Swagger UI, and test commands.
- Traditional Apache/PHP server install documentation.
- Service tutorial for pages, REST endpoints, database work, OpenAPI, and tests.
- AI-assisted development guidance focused on visible conventions, self-review, and lower human review cost.

The main gap is not implementation depth. The main gap is discovery: README, GitHub repository metadata, Packagist metadata, and article entry points should make the project easier to understand from outside.

## Positioning

Use this positioning consistently:

- Renovation, not rewrite.
- Small-service framework, not enterprise full-stack framework.
- Legacy-friendly, not legacy-bound.
- Review-friendly first: human-written and AI-assisted changes should follow the same visible conventions.
- Human-readable and AI-readable because conventions are stable and explicit.
- Modern enough to be safe and testable, but intentionally not pattern-heavy when a simple visible convention is enough.
- Explicit URL routing and controller methods over hidden framework magic.
- OpenAPI and tests around real endpoints, not documentation for its own sake.

Avoid these messages:

- Do not call NeNe a Laravel, Symfony, CodeIgniter, or Laminas replacement.
- Do not attack modern design patterns. Explain that NeNe optimizes for small-service reviewability when the team does not want reviewer availability to depend on specialist pattern knowledge.
- Do not overstate AI support. Say that NeNe is reviewable for human-written or AI-assisted changes.
- Do not imply production readiness for large multi-team systems.
- Do not hide that Smarty and URL-segment routing are intentional old-school choices.

## Preparation Order

### 1. GitHub Repository Face

Before broad outreach, update the repository surface:

- Set GitHub About description.
- Set homepage to `https://nene-php.com/`.
- Add repository topics:
  - `php`
  - `php-framework`
  - `framework`
  - `openapi`
  - `smarty`
  - `docker`
  - `legacy-modernization`
  - `micro-framework`
- Confirm GitHub detects the MIT license.
- Keep GitHub Releases synced with `docs/releases.md`.

Suggested About description:

```text
A tiny renovated legacy-style PHP framework for reviewable small services: URL routing, Smarty, REST, OpenAPI, Docker, and tests.
```

### 2. README Public Entry

The README should help a first-time visitor decide whether NeNe is relevant in less than one minute.

Add or strengthen:

- Demo link: `https://nene-php.com/`.
- Latest release link.
- License badge or visible MIT text.
- Quick Start.
- "What NeNe is" and "What NeNe is not".
- A short review-cost message: "same visible shape for human-written and AI-assisted changes."
- Link to the service tutorial.
- Link to the AI self-review checklists.
- Link to GitHub Releases.

The README should stay concise. Detailed setup and design explanations belong in `docs/`.

### 3. Composer and Packagist Metadata

If NeNe should be discoverable as a PHP package, improve `composer.json` before Packagist registration:

- Add a PHP requirement that matches the documented target.
- Add `keywords`.
- Add `homepage`.
- Add `support.issues`.
- Add `authors`.
- Clarify whether NeNe is used as a package, an application skeleton, or a repository to clone.

Packagist expectations should be explicit. If the recommended install path is still `git clone`, say that clearly in README and release notes.

### 4. Reference Implementation

Complete #145 before the widest outreach if possible.

The reference implementation should show the expected shape for a small NeNe feature:

- Page controller and Smarty template.
- Method-specific REST endpoint.
- Service/use-case boundary for business logic when needed.
- Mapper/model use.
- Transaction pattern when multiple database writes are involved.
- OpenAPI update.
- Focused tests.
- AI self-review checklist usage.

This gives articles a concrete feature to point at instead of only describing framework philosophy.

## Japanese Article Strategy

Use Zenn and Qiita for different purposes.

### Zenn: Philosophy and Renovation Story

Zenn is best for the project story.

Possible title:

```text
10年前の自作PHPフレームワークを、PHP 8.4時代向けにリフォームした話
```

Recommended outline:

1. Why not rewrite everything.
2. Why keep `/{controller}/{action}` routing.
3. Why keep Smarty and lightweight mappers.
4. What was modernized: Composer, Docker, OpenAPI, PHPUnit, Phan, PHP CS Fixer.
5. Security hardening: sessions, CSRF, password hashing, JSON-only responses.
6. Why small explicit conventions reduce review cost for human-written and AI-assisted changes.
7. What NeNe is not.
8. Link to GitHub, demo, release, and tutorial.

Tone:

- Personal and honest.
- Explain the target users.
- Avoid sounding like a new mainstream framework launch.

### Qiita: Hands-on Implementation Guide

Qiita is best for practical steps.

Possible title:

```text
NeNeで固定ページとREST APIを追加する
```

Recommended outline:

1. Clone and Docker startup.
2. Confirm top page and health check.
3. Add a fixed page.
4. Add method-specific REST handlers.
5. Add mapper/model code.
6. Add transaction boundary when needed.
7. Update OpenAPI.
8. Run tests and analysis.

Use `docs/tutorials/building-a-service.md` as the source material.

## English Outreach Strategy

Start with low-pressure channels before posting to highly critical communities.

### DEV Community

Best first English article target.

Possible title:

```text
Renovating a tiny legacy-style PHP framework for modern PHP
```

Core message:

- Renovation, not rewrite.
- Small explicit conventions.
- URL routing and controller methods are visible.
- Reviewers can find HTTP input, business rules, SQL, API contracts, and tests in predictable places.
- Review participation should not require deep familiarity with trendy architecture patterns before basic behavior can be checked.
- OpenAPI and tests were added around the old shape.
- Not a Laravel or Symfony competitor.

### Hashnode or Personal Blog

Use for a longer English version if continuing the story.

Good angle:

- "What I kept."
- "What I modernized."
- "What I intentionally did not add."

### Reddit

Use carefully, preferably after README and Packagist metadata are improved.

Suggested framing:

```text
I renovated a tiny legacy-style PHP framework instead of rewriting it. Feedback welcome.
```

Post to `r/PHP` only when ready for direct technical criticism. Avoid promotional wording.

### Hacker News

Use only after the public entry is polished:

- README is strong.
- Demo works.
- GitHub Release is visible.
- Reference implementation exists.
- Packagist or install story is clear.

Suggested framing:

```text
Show HN: NeNe, a tiny renovated legacy-style PHP framework
```

## Suggested Execution TODO

1. Improve GitHub repository metadata.
2. Strengthen the README public entry.
3. Improve Composer/Packagist metadata.
4. Complete #145 reference implementation.
5. Write the Zenn renovation story.
6. Write the Qiita hands-on guide.
7. Publish a short DEV Community English article.
8. Consider Reddit or Hacker News after feedback from the first articles.

## Success Criteria

NeNe's first public outreach is successful if readers can quickly understand:

- Who NeNe is for.
- Why it keeps a legacy-style shape.
- How it lowers review cost by keeping implementation patterns consistent.
- Why it avoids making reviewer availability depend on specialized design-pattern fluency.
- How to run it locally.
- How to build a first feature.
- Why it is intentionally smaller than mainstream frameworks.
- Where to find releases, docs, demo, and source code.
