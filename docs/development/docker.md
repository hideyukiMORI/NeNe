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

## MySQL Development Database

Docker Compose starts a MySQL 8.4 service for local development. The application container receives these database settings automatically:

```text
type: MySQL
host: mysql
port: 3306
database: nene
user: nene
password: nene
```

The first MySQL startup runs `docker/mysql/init/001_schema.sql`, which creates:

- `users`
- `todos`

It also inserts the default development account and sample TODO rows.

The MySQL container is exposed on host port `3307` by default to avoid colliding with a host MySQL server.

Default development account:

```text
user: admin
password: admin
```

Do not use this account in production.

To expose MySQL on another host port:

```sh
NENE_MYSQL_PORT=3308 docker compose up --build
```

To change development credentials, set the Compose variables before startup:

```sh
NENE_DB_NAME=nene_local NENE_DB_USER=nene NENE_DB_PASS=nene docker compose up --build
```

## Optional SQLite Initialization

The legacy SQLite initializer remains available for non-Docker or fallback usage:

```sh
docker compose run --rm app sh -lc "printf 'Y\n' | php cli/initSQLite.php"
```

## Stop

```sh
docker compose down
```

## Notes

- The Apache document root is `htdocs/`.
- `mod_rewrite` is enabled so `htdocs/.htaccess` can route URLs to `index.php`.
- Composer dependencies are stored in a Docker named volume named `vendor`.
- MySQL data is stored in a Docker named volume named `mysql-data`.
- Generated files under `log/`, `view/compile/`, and SQLite database files are ignored by Git.
