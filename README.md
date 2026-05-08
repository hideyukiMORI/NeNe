# NeNe

Simple web application framework.

NeNe is a renovated legacy PHP framework.

The original idea is more than ten years old: keep a tiny front-controller framework that people familiar with older PHP frameworks can understand at a glance. This repository keeps that philosophy and structure, then updates the project for modern PHP development with Composer, Docker, tests, OpenAPI, explicit security defaults, and current stable packages.

NeNe is intentionally much smaller than full-stack frameworks. It keeps only the parts needed to build small services:

- Convention-based routing from URL segments.
- Server-rendered pages with Smarty.
- Method-specific REST handlers for JSON APIs.
- Lightweight database mappers.
- Session, CSRF, error catalog, logging, and testable boundaries.

The goal is not to replace Laravel, Symfony, CodeIgniter, or Laminas. The goal is to give legacy-framework users a small codebase they can read, keep, and safely modernize.

## Documentation

- Project overview: `docs/project.md`
- Documentation index: `docs/README.md`
- Contributor guide: `docs/CONTRIBUTING.md`
- Workflow: `docs/workflow.md`
- Coding standards: `docs/development/coding-standards.md`
- Docker development: `docs/development/docker.md`
- Server install: `docs/deployment/server-install.md`
- Testing: `docs/development/testing.md`
- AI agent guide: `AGENTS.md`

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

## Requirements

- The Docker development target is PHP 8.4.
- Composer is required for dependency installation and autoloading.

## Docker Quick Start

```sh
docker compose up --build
```

Open `http://localhost:8080/`.

Docker Compose starts MySQL 8.4 and initializes the development `users` and `todos` tables automatically. The default development login is `admin` / `admin`.

For a traditional Apache/PHP server install, run Composer and then initialize the sample database:

```sh
composer install --no-dev --optimize-autoloader
php cli/setupDatabase.php --env=.env --yes
```

If you intentionally use the SQLite3 fallback, initialize the SQLite sample file instead:

```sh
php cli/initSQLite.php
```

## Testing

```sh
composer test
```

## License

Proprietary.
