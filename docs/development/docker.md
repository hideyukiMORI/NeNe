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

phpMyAdmin is also available for the local MySQL development database:

```text
http://localhost:8081/
```

phpMyAdmin does not have a separate NeNe-specific account. Log in with a MySQL user from the development database container:

```text
server: mysql
user: nene
password: nene
```

For administrative database inspection, the default MySQL root account is:

```text
user: root
password: root
```

The `admin` / `admin` credential is only the sample app login seeded into the `users` table. It is not a phpMyAdmin or MySQL login.

The phpMyAdmin image includes the darkwolf theme for phpMyAdmin 5.2. It is intended only for trusted local development; do not expose this service in production.

## Environment Variables

Docker Compose includes local development defaults, so `.env` is optional. To customize ports or database credentials, copy the committed example and edit your local file:

```sh
cp .env.example .env
```

The local `.env` file is ignored by Git. Keep real secrets out of the repository.

The application reads runtime, session, and database settings through `getenv()` in `ini/xSystemIni.php`. In Docker, Compose injects the connection type, host, and container port into the app service. The shared credentials and exposed host ports can be changed through `.env`.

Use another port if needed:

```sh
NENE_PORT=8082 docker compose up --build
```

Use another phpMyAdmin port if needed:

```sh
NENE_PHPMYADMIN_PORT=8083 docker compose up --build
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

These defaults come from `.env.example` and the fallback values in `compose.yaml`. When you copy `.env.example` to `.env`, these variables control the Docker MySQL account used by the app and phpMyAdmin:

```text
NENE_DB_NAME=nene
NENE_DB_USER=nene
NENE_DB_PASS=nene
NENE_DB_ROOT_PASS=root
```

MySQL applies these account values when the database volume is initialized for the first time. If `mysql-data` already exists, changing `.env` does not automatically rewrite existing MySQL users.

The first MySQL startup runs `docker/mysql/init/001_schema.sql`, which creates:

- `users`
- `todos`

It also inserts the default development account and sample TODO rows.

The MySQL container is exposed on host port `3307` by default to avoid colliding with a host MySQL server.

The phpMyAdmin container connects to MySQL through the internal Compose service name `mysql` and is exposed on host port `8081` by default. It uses the MySQL credentials shown above, and selects the bundled darkwolf theme by default.

Default sample app account:

```text
user: admin
password: admin
```

The seed stores this password with PHP `password_hash()` and the app verifies it with `password_verify()`. The credential is only for the local sample; do not use this account in production.

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

For SQLite-only sample data, `NENE_SAMPLE_ADMIN_PASSWORD` can override the inserted admin password before it is hashed:

```sh
NENE_DB_TYPE=SQLite3 NENE_SAMPLE_ADMIN_PASSWORD=local-secret docker compose run --rm app sh -lc "printf 'Y\n' | php cli/initSQLite.php"
```

To run the application against SQLite instead of the Docker MySQL service, start the app with SQLite environment values:

```sh
NENE_DB_TYPE=SQLite3 NENE_DB_FILE=nene.db docker compose up --build app
```

## Schema Parity Between SQLite and MySQL

NeNe initializes two separate development databases through two separate scripts:

- `cli/initSQLite.php` — PHP code that creates SQLite tables and `updated_at` triggers when the SQLite3 fallback is used.
- `docker/mysql/init/001_schema.sql` — declarative SQL applied by the MySQL container on first boot under Docker Compose.

These files **do not share a source of truth**. Nothing in CI enforces parity between them. When a contributor adds or alters a table, both files must be edited in the same change, and both runtimes must be verified locally.

When in doubt about whether the two paths agree, compare the table sets:

```sh
docker compose down -v && docker compose up -d app

# MySQL tables (root password is the Docker dev default; never use this in production).
docker compose exec mysql mysql -uroot -proot nene -e 'SHOW TABLES;'

# SQLite tables (run after `php cli/initSQLite.php`).
docker compose exec app sh -lc "printf 'Y\n' | php cli/initSQLite.php"
docker compose exec app php -r '$pdo = new PDO("sqlite:/var/www/html/data/nene.db"); foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type=\"table\" ORDER BY name") as $r) echo $r["name"], PHP_EOL;'
```

If the two outputs disagree, the schema files have drifted. The two paths are intentionally kept in parallel for now because the legacy SQLite-and-MySQL split predates the project's modernization step; a future ADR could consolidate to a shared schema source (for example, generating the SQLite path from the MySQL SQL) but that decision is out of scope here.

This note exists because adding or altering tables in only one path is a silent regression source — the runtime continues to look healthy on the path that received the change while breaking on the other.

## Stop

```sh
docker compose down
```

## Clearing the Smarty Compile Cache

`view/compile/` is written by the container's `www-data` user, so a host-side `rm view/compile/*` fails with `Permission denied`. To force a full recompile of all templates during development:

```sh
docker compose exec -T app find view/compile -type f -delete
```

The cache is regenerated on the next page request. Smarty also recompiles automatically when a `.tpl` source file's mtime changes; the manual delete is only needed when the cache itself looks stale (e.g. after editing a Smarty plugin or config).

## Notes

- The Apache document root is `htdocs/`.
- `mod_rewrite` is enabled so `htdocs/.htaccess` can route URLs to `index.php`.
- Composer dependencies are stored in a Docker named volume named `vendor`.
- MySQL data is stored in a Docker named volume named `mysql-data`.
- phpMyAdmin is a local development helper and should not be exposed from production deployments.
- Generated files under `log/`, `view/compile/`, and SQLite database files are ignored by Git.
