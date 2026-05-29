# NeNe

Tiny renovated legacy-style PHP framework for reviewable small services.

- Demo: <https://nene-php.com/>
- Latest release: [`v0.3.0`](https://github.com/hideyukiMORI/NeNe/releases/tag/v0.3.0)
- License: MIT
- Service tutorial: `docs/tutorials/building-a-service.md`
- AI self-review checklists: `docs/ai/README.md`

NeNe is a renovated legacy PHP framework.

The original idea is more than ten years old: keep a tiny front-controller framework that people familiar with older PHP frameworks can understand at a glance. This repository keeps that philosophy and structure, then updates the project for modern PHP development with Composer, Docker, tests, OpenAPI, explicit security defaults, and current stable packages.

NeNe is intentionally much smaller than full-stack frameworks. Its visible conventions are meant to stay readable by both humans and AI agents, so code generated or edited with AI assistance can follow the same shape a developer would normally review. The goal is to lower code review cost for small services by keeping HTTP input, controller actions, service rules, mapper SQL, OpenAPI, and tests in predictable places.

NeNe keeps only the parts needed to build small services:

- Convention-based routing from URL segments.
- Server-rendered pages with Smarty.
- Method-specific REST handlers for JSON APIs.
- Lightweight database mappers.
- Session, CSRF, error catalog, logging, and testable boundaries.

The goal is not to replace Laravel, Symfony, CodeIgniter, or Laminas. The goal is to give legacy-framework users a small codebase they can read, keep, safely modernize, and move from clone to a working small-service delivery path quickly.

## What NeNe Is Not

- Not a large enterprise full-stack framework.
- Not a Laravel, Symfony, CodeIgniter, or Laminas replacement.
- Not an ORM, plugin ecosystem, or SPA-first architecture.
- Not an attempt to hide legacy-style URL routing behind a new router abstraction.

## Documentation

- Project overview: `docs/project.md`
- Documentation index: `docs/README.md`
- Contributor guide: `docs/CONTRIBUTING.md`
- Workflow: `docs/workflow.md`
- Coding standards: `docs/development/coding-standards.md`
- Docker development: `docs/development/docker.md`
- Server install: `docs/deployment/server-install.md`
- Releases: `docs/releases.md`
- Testing: `docs/development/testing.md`
- AI agent guide: `AGENTS.md`
- MCP bridge (external): [nene-mcp](https://github.com/hideyukiMORI/nene-mcp) — Composer plugin for local stdio MCP; see [MCP with NeNe](#mcp-with-nene) below

## Routing

NeNe routes URLs by convention:

```text
/{controller}/{action}
```

The dispatcher resolves the URL to:

- `Nene\Controller\{Controller}Controller`
- `{action}Action` for server-rendered pages
- `{action}{HttpMethod}Rest` for JSON/API responses, such as `indexGetRest` or `loginPostRest`

The legacy `{action}Rest` fallback remains only for compatibility. New REST endpoints should use method-specific handlers.

URL parameters are passed in the `key_value` segment form (for example `/todo/item/id_42`), not REST `{id}` templates. See `docs/development/coding-standards.md` for the full convention.

## Requirements

- The Docker development target is PHP 8.4.
- Composer is required for dependency installation and autoloading.
- **`ext-intl`** is required for bare-metal PHP installs. Dev dependencies (Symfony String, PHP-CS-Fixer, and related tooling) fail at bootstrap without it. The Docker image installs `intl`; on WSL or host PHP, enable the extension before `composer install` (for example `apt install php8.4-intl` on Debian/Ubuntu).

## Recommended Install Path

For now, NeNe is intended to be cloned as a small-service base repository:

```sh
git clone git@github.com:hideyukiMORI/NeNe.git
cd NeNe
composer install
```

Composer is used after cloning to install dependencies and build autoloading. NeNe is not currently positioned as a library that should be added to an existing application with `composer require`.

The Composer package metadata uses `type: project` for public discovery and future Packagist compatibility. The recommended install story remains repository clone unless a later release changes the distribution model.

## Docker Quick Start

```sh
docker compose up --build
```

Open `http://localhost:8080/`.

Docker Compose starts MySQL 8.4 and initializes the development `users` and `todos` tables automatically. The default sample app login is `admin` / `admin`.

For local database inspection, open phpMyAdmin at `http://localhost:8081/` and log in with the MySQL development database user `nene` / `nene`. The MySQL root user is `root` / `root` by default. These are Docker-only defaults and can be changed through `.env`. The Docker image includes the darkwolf phpMyAdmin theme.

For a traditional Apache/PHP server install, run Composer and then initialize the sample database:

```sh
composer install --no-dev --optimize-autoloader
php cli/setupDatabase.php --env=.env --yes
```

If you intentionally use the SQLite3 fallback, initialize the SQLite sample file instead:

```sh
php cli/initSQLite.php
```

SQLite database files under `data/` are generated locally and are not committed.

## Testing

```sh
composer test
```

`composer test` runs the unit suite. HTTP smoke tests (`composer test:http`) are conditional on `NENE_HTTP_BASE_URL` — see `docs/development/testing.md` for the runtime workflow.

## MCP with NeNe

NeNe does **not** embed MCP in framework core. Use the sibling package **[nene-mcp](https://github.com/hideyukiMORI/nene-mcp)** to expose local OpenAPI-backed REST as MCP tools for Cursor, Claude Desktop, and other MCP hosts.

```sh
composer require hideyukimori/nene-mcp
```

Add a NENE2-compatible tool catalog (convention: `docs/mcp/tools.json`) aligned with `docs/api/openapi.yaml`, then point your MCP client at `vendor/bin/nene-mcp`. Integration details:

- **[nene-mcp documentation site](https://hideyukimori.github.io/nene-mcp/howto/integrate-nene)** — bootstrap NeNe + MCP (integrator-facing)
- [NeNe integration guide (repo)](https://github.com/hideyukiMORI/nene-mcp/blob/main/docs/integration/nene.md)
- [Health catalog example (docs site)](https://hideyukimori.github.io/nene-mcp/howto/health-catalog-example)

MCP stdio handling stays in `vendor/`; do not add MCP code under `class/xion/`.

## License

MIT.
