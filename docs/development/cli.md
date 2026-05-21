# CLI Tools

NeNe ships two command-line scripts under `cli/`. This page describes when to use each one.

## `cli/setupDatabase.php` (canonical)

```sh
php cli/setupDatabase.php [--env=.env] [--yes] [--help]
```

The canonical installer for both **MySQL** and **SQLite** runtimes. Reads `NENE_DB_TYPE` (default `SQLite3` when no env is loaded) to decide which adapter to use, then calls `Nene\Xion\DatabaseInstaller::install()` to create the sample tables (`users`, `todos`) and insert the default `admin` development account.

Options:

- `--env=PATH` — load a dotenv-style file before NeNe initializes configuration. Process environment variables take priority over the file. When `PATH` is explicitly passed and does not exist, the script exits with an error rather than silently falling back to the process environment.
- `--yes` — skip the interactive confirmation prompt. Required for CI / non-interactive deployment.
- `--help` — show usage and exit.

Recommended one-liner for first-run deployment:

```sh
php cli/setupDatabase.php --env=.env --yes
```

Or via the Composer shortcut:

```sh
composer setup
```

The script is **idempotent** — running it again on an installed database is a no-op (CREATE TABLE IF NOT EXISTS + INSERT WHERE NOT EXISTS). It also prints a final health summary (`Health: API=OK DB=OK Schema=OK`) so deployment scripts can grep for the result.

For `--yes` + bad DB credentials, the script exits non-zero with a clear error (e.g. `Database setup failed: SQLSTATE[HY000] [2002] php_network_getaddresses: getaddrinfo for ... failed`).

## `cli/initSQLite.php` (legacy)

```sh
php cli/initSQLite.php [--yes] [--help]
```

Older, SQLite-only initializer that predates `setupDatabase.php`. It hard-codes the SQLite adapter and embeds its own copy of the schema, so it does not honor `NENE_DB_TYPE` and is not used by `composer setup`.

Kept for backwards compatibility with existing deployment scripts. New deployment guides should use `cli/setupDatabase.php` instead, even for the SQLite case:

```sh
NENE_DB_TYPE=SQLite3 php cli/setupDatabase.php --env=.env --yes
```

If you do invoke `cli/initSQLite.php` directly, the `--yes` and `--help` flags work the same way as for `setupDatabase.php`.

## When to use which

| Scenario | Use |
| --- | --- |
| Standard deployment (MySQL or SQLite) | `php cli/setupDatabase.php --env=.env --yes` (or `composer setup`) |
| CI / Ansible / Docker provisioning | same as above, `--yes` is essential |
| Existing scripts that already call `cli/initSQLite.php` | keep working; migrate to `setupDatabase.php` at the next maintenance window |
| Fresh contributor running locally | `composer setup` (shortest) |
| Reset and reinstall sample data | `setupDatabase.php` is idempotent; just re-run it. Soft-delete the existing rows first if you want a clean slate, or `docker compose down -v` to drop the MySQL volume entirely. |

## Schema source-of-truth (three sites)

Both scripts pull from independent copies of the CREATE TABLE statements:

- `docker/mysql/init/001_schema.sql` — MySQL container's first-boot init.
- `cli/initSQLite.php` — embedded SQLite DDL (legacy path).
- `class/xion/DatabaseInstaller.php` — embedded MySQL + SQLite DDL (canonical path used by `cli/setupDatabase.php`).

When you add or alter a table, **edit all three** in the same change. CI does not enforce parity. See `docs/development/docker.md` "Schema Parity Between SQLite and MySQL" for the full rationale and the table-set comparison commands.

## Related

- `docs/deployment/server-install.md` — full deployment walkthrough.
- `docs/development/docker.md` — local Docker setup, including `NENE_*` environment variables.
- `composer.json` — `setup`, `test`, `test:http`, `analyze`, `format`, `check` shortcuts.
