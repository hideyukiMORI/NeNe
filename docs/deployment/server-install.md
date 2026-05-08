# Server Install Guide

This guide explains how to install NeNe on a traditional Apache/PHP server after `git clone`.

Docker is still the recommended local development environment. A server install is for production-like hosting, shared hosting, VPS, or a small Apache/PHP server where NeNe is deployed directly.

## Confirmed Short Path

The shortest confirmed path is:

```sh
git clone git@github.com:hideyukiMORI/NeNe.git
cd NeNe
composer install --no-dev --optimize-autoloader
```

This flow has been confirmed with the public sample site at <https://nene-php.com/>.

After Composer finishes, point the web server document root to:

```text
/path/to/NeNe/htdocs
```

Open the configured domain and confirm that the NeNe top page appears.

Then create the sample database tables:

```sh
php cli/setupDatabase.php --env=.env --yes
```

The command is safe to run more than once. It creates the current sample `users` and `todos` tables when missing and inserts the default `admin` sample user only when that user does not already exist.

## Requirements

- PHP 8.4 is the project target.
- Composer is required before deployment.
- Apache must allow URL rewriting for `htdocs/.htaccess`.
- PHP extensions depend on the database:
  - `pdo_mysql` for MySQL.
  - `pdo_sqlite` for SQLite fallback.
- The deployed user must be able to read the project files.
- Runtime directories such as `data/` and Smarty cache directories must be writable when the selected runtime uses them.

## Important Directory Rule

Only `htdocs/` should be public.

Do not expose the repository root as the web document root. The repository root contains application code, configuration, documentation, tests, and Composer metadata.

Recommended layout:

```text
/home/example/NeNe/
    class/
    config/
    docs/
    htdocs/        <- Apache DocumentRoot
    ini/
    vendor/
    view/
```

If a hosting provider forces a directory such as `public_html`, prefer setting that directory to point to `NeNe/htdocs` from the hosting control panel. Avoid copying the entire repository into a public directory unless the server is configured so only `htdocs/` is reachable.

## Composer Notes

Run Composer from the repository root:

```sh
composer install --no-dev --optimize-autoloader
```

Use `--no-dev` on production servers so PHPUnit, Phan, PHP CS Fixer, and other development-only tools are not installed.

If Composer fails with:

```text
/usr/bin/env: php: No such file or directory
```

the shell cannot find the PHP CLI binary. This is a server PATH issue, not a NeNe application error.

Check which PHP command is available:

```sh
which php
php -v
```

On shared hosting, PHP may live under a versioned path. Use the hosting provider's documented PHP CLI path, or run Composer through that binary directly when needed:

```sh
/path/to/php /path/to/composer install --no-dev --optimize-autoloader
```

## Environment Variables

NeNe reads runtime settings with `getenv()` in `ini/xSystemIni.php`.

On a traditional server, copy `.env.example` to `.env` in the repository root and edit the values:

```sh
cp .env.example .env
vi .env
```

The web front controller loads the repository-root `.env` before NeNe initializes configuration. Existing server or process environment values still take priority over file values, so hosting-level settings can override `.env` safely.

You can also configure values through the server itself:

- Hosting control panel environment variable settings.
- Apache `SetEnv` directives.
- Web server or process manager configuration.
- A server-specific bootstrap approach approved for that hosting environment.

The setup CLI also loads `.env` explicitly when you pass `--env=.env`:

```sh
php cli/setupDatabase.php --env=.env --yes
```

Common production values:

```apacheconf
SetEnv NENE_APP_ENV production
SetEnv NENE_APP_DEBUG 0
SetEnv NENE_SESSION_SECURE 1
SetEnv NENE_SESSION_HTTPONLY 1
SetEnv NENE_SESSION_SAMESITE Lax
```

Database values for MySQL:

```apacheconf
SetEnv NENE_DB_TYPE MySQL
SetEnv NENE_DB_HOST localhost
SetEnv NENE_DB_PORT 3306
SetEnv NENE_DB_NAME nene
SetEnv NENE_DB_USER nene_user
SetEnv NENE_DB_PASS change_me
```

Do not commit real passwords, tokens, or hosting-specific `.env` files.

## Database Setup

Docker creates the development MySQL schema automatically. A traditional server does not.

For production MySQL, create:

- A database.
- A database user with only the permissions needed by the application.
- Tables compatible with the current sample schema.

The sample TODO app expects:

- `users`
- `todos`

Use the Docker initialization SQL and SQLite initializer as references when preparing a server schema. Keep the sample `admin` account only for local testing; replace or remove it for production use.

For the current sample runtime, use the setup command:

```sh
php cli/setupDatabase.php --env=.env --yes
```

The command loads a dotenv-style file before NeNe initializes configuration. Existing process environment variables take priority over the file, so hosting-level settings still win.

Useful options:

```sh
php cli/setupDatabase.php --help
php cli/setupDatabase.php --env=.env
php cli/setupDatabase.php --env=/absolute/path/to/nene.env --yes
```

Expected success output includes:

```text
Database setup completed.
Tables: users, todos
Sample account: admin / admin
Health: API=OK DB=OK Schema=OK
```

The browser health endpoint returns the same idea through the NeNe JSON envelope:

```json
{
  "Result": true,
  "Data": {
    "status": "success",
    "errorCode": "",
    "healthStatus": "ok",
    "api": true,
    "database": true,
    "schema": true,
    "databaseType": "MySQL"
  }
}
```

## SQLite Option

When no database environment variables are provided, NeNe can fall back to SQLite-oriented defaults.

Initialize the SQLite sample data from the repository root:

```sh
php cli/initSQLite.php
```

SQLite is useful for a very small sample deployment or quick hosting verification. MySQL is recommended once the application stores real service data.

## Apache Rewrite

NeNe uses URL-based routing:

```text
/{controller}/{action}
```

Examples:

```text
/index/index
/serverinstall/index
/todo/index
```

The front controller is `htdocs/index.php`, and `htdocs/.htaccess` routes clean URLs into it. Apache must allow `.htaccess` rewrite rules for the `htdocs/` directory.

If direct access to `/index/index` returns a 404 from Apache, check:

- `mod_rewrite` is enabled.
- `AllowOverride` permits rewrite rules.
- The document root is `htdocs/`.
- The `.htaccess` file was uploaded.

## Deployment Check

After installation, verify:

```sh
composer install --no-dev --optimize-autoloader
php cli/setupDatabase.php --env=.env --yes
```

Then open these URLs in a browser:

- `/`
- `/index/index`
- `/health/index`
- `/serverinstall/index`
- `/api-docs/`
- `/api-docs/openapi.php`

Expected behavior:

- The top page loads with the dark NeNe developer design.
- The top page health card shows `API: OK`, `DB: OK`, and `Schema: OK`.
- The server install page explains the deployment flow.
- The health endpoint returns a JSON response without exposing database credentials.
- Swagger UI opens.
- The OpenAPI YAML endpoint returns `application/yaml`.

## Production Checklist

- `NENE_APP_ENV=production`.
- `NENE_APP_DEBUG=0`.
- HTTPS is enabled.
- `NENE_SESSION_SECURE=1` under HTTPS.
- Real credentials are configured outside Git.
- The web document root is `htdocs/`, not the repository root.
- Development dependencies are not installed.
- Sample credentials are removed or changed.
- Database permissions are scoped to the application.
- File permissions allow runtime writes only where needed.
- Server logs are available for PHP and Apache errors.

## Troubleshooting

### Composer cannot find PHP

Use the PHP CLI path provided by the server:

```sh
/path/to/php /path/to/composer install --no-dev --optimize-autoloader
```

### Page opens but assets are missing

Confirm that the domain points to `htdocs/` and that `URI_ROOT` in `ini/xSystemIni.php` still matches the deployment path. Root deployments should keep `URI_ROOT` as `/`.

### Clean URLs return 404

Confirm Apache rewrite support and `.htaccess` loading.

### API returns internal server error

Temporarily inspect server logs. Keep `NENE_APP_DEBUG=0` in public production, and diagnose from logs rather than exposing details in the HTTP response.

### Login or TODO sample fails

Confirm that the database schema exists and the configured database user can read and write the expected tables.

Run:

```sh
php cli/setupDatabase.php --env=.env --yes
```

Then check:

```text
/health/index
```
