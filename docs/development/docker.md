# Docker Development

NeNe can run locally with Docker Compose.

## Requirements

- Docker
- Docker Compose v2

## Start

```sh
docker compose up --build
```

Open:

```text
http://localhost:8080/
```

Use another port if needed:

```sh
NENE_PORT=8081 docker compose up --build
```

## Initialize SQLite

The default configuration uses SQLite at `data/nene.db`.

To create the database and default admin user:

```sh
docker compose run --rm app sh -lc "printf 'Y\n' | php cli/initSQLite.php"
```

Default development account:

```text
user: admin
password: admin
```

Do not use this account in production.

## Stop

```sh
docker compose down
```

## Notes

- The Apache document root is `htdocs/`.
- `mod_rewrite` is enabled so `htdocs/.htaccess` can route URLs to `index.php`.
- Composer dependencies are stored in a Docker named volume named `vendor`.
- Generated files under `log/`, `view/compile/`, and SQLite database files are ignored by Git.
