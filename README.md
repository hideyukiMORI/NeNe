# NeNe

Simple web application framework.

NeNe is a legacy, simple, lightweight PHP framework. It uses a front controller and URL path segments to resolve controller classes and action methods.

## Documentation

- Project overview: `docs/project.md`
- Documentation index: `docs/README.md`
- Contributor guide: `docs/CONTRIBUTING.md`
- Workflow: `docs/workflow.md`
- Coding standards: `docs/development/coding-standards.md`
- Docker development: `docs/development/docker.md`
- AI agent guide: `AGENTS.md`

## Routing

NeNe routes URLs by convention:

```text
/{controller}/{action}
```

The dispatcher resolves the URL to:

- `Nene\Controller\{Controller}Controller`
- `{action}Action` for server-rendered pages
- `{action}Rest` for JSON/API responses

## Requirements

- PHP 8.1 or later is currently used for maintenance.
- Composer is required for dependency installation and autoloading.

## Docker Quick Start

```sh
docker compose up --build
```

Open `http://localhost:8080/`.

To initialize the SQLite database:

```sh
docker compose run --rm app sh -lc "printf 'Y\n' | php cli/initSQLite.php"
```

## License

Proprietary.
