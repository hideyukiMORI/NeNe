# Publication Strategy and Release Case Study

This document records how NeNe should be introduced after the `v0.2.0` release, and why that introduction should happen in this order.

It is intentionally public. Treat it as a case-study note for turning a small legacy-style PHP framework into a readable OSS project, not as a private marketing playbook. The value is in making the preparation process visible: what was modernized, what was intentionally kept small, what still needs proof, and how the project explains itself before asking outside developers for attention.

Keeping this plan in the repository also helps human contributors and AI agents share the same context. Public messaging, README work, reference implementations, and article drafts should all point to the same project shape instead of drifting into separate stories.

NeNe should not be presented as another general-purpose full-stack framework. Its strongest message is narrower and clearer:

> NeNe is a tiny renovated legacy-style PHP framework for readable small services. It keeps visible URL routing, controller actions, Smarty pages, lightweight mappers, REST endpoints, OpenAPI, Docker, tests, and explicit security boundaries.

The public angle should speak to a common developer pain:

> Have you ever lost time in code review just trying to understand the writer's style? NeNe aims to lower that cost by keeping small-service changes in the same visible shape, whether they are written by a person or assisted by an AI tool.

Another useful angle:

> Modern design patterns and fashionable coding styles can be powerful. But when implementation style is left completely open, review can slow down because every change requires reviewers to decode a different personal architecture. NeNe favors visible, repeatable conventions so reviewers can focus on behavior instead of first learning each contributor's preferred style.

This is not a claim that NeNe needs more outside reviewers. The point is structural: by narrowing ordinary implementation choices, NeNe reduces the number of design decisions a reviewer must understand before checking a small-service change.

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

## Why Keep This Public

This document should be useful even before any article is published.

- For maintainers, it keeps release preparation tied to concrete project gaps instead of vague promotion.
- For contributors, it explains why README, Packagist, reference implementation, and docs polish matter.
- For reviewers, it makes NeNe's positioning explicit enough to challenge before public outreach.
- For AI-assisted work, it gives agents a durable source for tone, audience, and scope.

What this document is not:

- It is not a promise to post on every listed site.
- It is not a growth-hacking checklist.
- It is not a claim that NeNe is ready for every production team.
- It is not criticism of Laravel, Symfony, modern PHP patterns, or developers who prefer them.

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
- Do not attack modern design patterns. Explain that NeNe optimizes for small-service reviewability when a team does not want every review to start by decoding a different personal architecture.
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

NeNe's current recommended install path is `git clone`, then `composer install`. Treat the repository as a small-service base, not as a library that is added to an existing application with `composer require`.

If NeNe should become discoverable on Packagist later, improve `composer.json` before registration:

- Add a PHP requirement that matches the documented target.
- Add `keywords`.
- Add `homepage`.
- Add `support.issues`.
- Add `authors`.
- Keep the README clear that the primary install path is still repository clone unless a later Issue changes the distribution model.

Packagist expectations should be explicit. Packagist can help discovery, but it should not imply that NeNe is currently a drop-in library for existing applications.

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

## English Outreach Candidates

Start with low-pressure channels before considering highly critical communities. The goal is to document the renovation story and invite technical feedback, not to maximize traffic.

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
- Reviews should not require deep familiarity with each author's preferred architecture before basic behavior can be checked.
- OpenAPI and tests were added around the old shape.
- Not a Laravel or Symfony competitor.

### Hashnode or Personal Blog

Use for a longer English version if continuing the story.

Good angle:

- "What I kept."
- "What I modernized."
- "What I intentionally did not add."

### Reddit

Consider only after README and Packagist metadata are improved.

Suggested framing:

```text
I renovated a tiny legacy-style PHP framework instead of rewriting it. Feedback welcome.
```

Post to `r/PHP` only when ready for direct technical criticism. Avoid promotional wording.

### Hacker News

Consider only after the public entry is polished:

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

1. Done: improve GitHub repository metadata.
2. Done: strengthen the README public entry.
3. Done: clarify that `git clone` is the recommended install path for now.
4. #176: improve Composer/Packagist-facing metadata for discovery.
5. #177: write the Zenn renovation story.
6. #178: write the Qiita hands-on guide.
7. #179: publish a short DEV Community English article.
8. #180: consider Reddit or Hacker News after feedback from the first articles.
9. #165/#145: continue the reviewable Controller-Service-Mapper reference implementation after the public-entry sequence.

## Success Criteria

NeNe's first public outreach is successful if readers can quickly understand:

- Who NeNe is for.
- Why it keeps a legacy-style shape.
- How it lowers review cost by keeping implementation patterns consistent.
- Why it avoids making ordinary reviews depend on decoding each author's personal architecture.
- How to run it locally.
- How to build a first feature.
- Why it is intentionally smaller than mainstream frameworks.
- Where to find releases, docs, demo, and source code.
