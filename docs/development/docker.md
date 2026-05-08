# Docker Development

NeNe can run locally with Docker Compose.

## Requirements

- Docker
- Docker Compose v2

The application container uses PHP 8.4 as the Docker development target.

## Start

```sh
docker compose up --build
```

Open:

```text
http://localhost:8080/
```

## Environment Variables

Docker Compose includes local development defaults, so `.env` is optional. To customize ports or database credentials, copy the committed example and edit your local file:

```sh
cp .env.example .env
```

The local `.env` file is ignored by Git. Keep real secrets out of the repository.

The application reads runtime, session, and database settings through `getenv()` in `ini/xSystemIni.php`. In Docker, Compose injects the connection type, host, and container port into the app service. The shared credentials and exposed host ports can be changed through `.env`.

Use another port if needed:

```sh
NENE_PORT=8081 docker compose up --build
```

## Session Cookie Settings

The app explicitly configures PHP session Cookie attributes before `session_start()`:

```text
HttpOnly: enabled
SameSite: Lax
Secure: disabled for local HTTP development
Lifetime: browser session
```

For production behind HTTPS, set:

```sh
NENE_APP_ENV=production NENE_APP_DEBUG=0 NENE_SESSION_SECURE=1 docker compose up --build
```

`Secure` is not enabled by default in local Docker because the sample app is served over plain `http://localhost:8080/`. Enabling it on plain HTTP prevents browsers from sending the session Cookie.

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

The SQLite initializer remains available for non-Docker or fallback usage. It creates the same sample tables as the MySQL initializer and inserts the default `admin` account with sample TODO rows:

```sh
docker compose run --rm app sh -lc "printf 'Y\n' | php cli/initSQLite.php"
```

To run the application against SQLite instead of the Docker MySQL service, start the app with SQLite environment values:

```sh
NENE_DB_TYPE=SQLite3 NENE_DB_FILE=nene.db docker compose up --build app
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
