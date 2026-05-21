# Database Self-Review

Use this checklist for schema additions, mapper changes, MySQL ↔ SQLite parity edits, transactions, seed data, and per-entity model updates.

Source policies:

- `docs/development/coding-standards.md` (data mapper conventions, junction-table guidance from PR #239)
- `docs/development/docker.md` (Schema Parity Between SQLite and MySQL section, PR #240)
- `docs/tutorials/building-a-service.md` (sections "Add Database Code", "Add Database Transactions")

## Checklist

- [ ] Schema changes are mirrored in **all three** locations: `docker/mysql/init/001_schema.sql`, `cli/initSQLite.php`, and `class/xion/DatabaseInstaller.php` (used by `cli/setupDatabase.php`). The three sites do not share a source of truth; CI does not enforce parity.
- [ ] SQLite path includes an `..._updated_at_trigger` to mirror MySQL's `ON UPDATE CURRENT_TIMESTAMP`.
- [ ] Foreign keys cascade-delete or restrict deliberately; the choice matches the entity lifecycle.
- [ ] Per-user tables expose `user_id` as a `BIGINT UNSIGNED` FK to `users.id`, indexed (`KEY ..._user_id_index`).
- [ ] Soft delete (`is_deleted TINYINT(1)`) is the default; physical delete is justified per entity.
- [ ] Unique constraints on soft-deleted columns have an explicit policy (FT2 follow-ups entry in `docs/field-trials/follow-ups.md` documents the open trade-off — choose namespacing-in-tests, or partial-unique-index, deliberately).
- [ ] Model class under `class/db/` declares `$schema` and `$validation` matching the table; field types use `DataModelBase` constants (`INTEGER`, `STRING`, `BOOLEAN`, `DATETIME`).
- [ ] Mapper extends `DataMapperBase` with `MODEL_CLASS`, `TARGET_TABLE`, `KEY_SID` constants set.
- [ ] Per-user finders take `int $userId` as the first parameter (`findRowsByUserId`, `findRowByUserIdAndId`, `createForUser`, `updateForUser`, `deleteForUser`) and bind it as `PDO::PARAM_INT`.
- [ ] Junction tables (M:N) use raw prepared statements (`DataMapperBase` composite-PK assumption breaks for them; see PR #239 / FT2 F-6 guidance in `docs/development/coding-standards.md`).
- [ ] Multi-statement writes wrap in `TransactionManager::run()`; domain validation runs before opening the transaction. Throwing `DomainException` from inside is allowed (PR #244 / ADR if introduced) and is mapped to JSON 4xx by `htdocs/index.php`.
- [ ] Seed data is idempotent (`INSERT ... WHERE NOT EXISTS` or equivalent) so re-running init does not duplicate rows.
- [ ] Test setup uses direct SQL cleanup (`UPDATE table SET is_deleted = 1` via `PdoConnection::getInstance()->exec(...)`) when the entity has no destructive HTTP endpoint; call `Initialize::init()` once per test class before touching framework PDO directly.
- [ ] `composer test` and `composer test:http` both pass after schema recreate (`docker compose down -v && docker compose up -d app`).
- [ ] PR body lists this checklist when schema or mappers change.
