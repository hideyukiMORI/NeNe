# Xion Class Self-Review

Use this checklist when adding a new class to `class/xion/`.
These classes are framework-level building blocks — hold them to a higher standard than feature code.

## Before Starting

- [ ] Check `class/xion/INDEX.md` — a class for this purpose may already exist.
- [ ] The feature has a GitHub Issue (`FTxxx`).
- [ ] The class name clearly signals its domain (e.g. `AuditLog`, not `Logger`).

## Class Structure

- [ ] Constructor uses the PDO injection pattern:
  ```php
  public function __construct(private readonly ?PDO $db = null) {}
  private function db(): PDO { return $this->db ?? PdoConnection::getInstance(); }
  ```
- [ ] No static state; all data flows through constructor injection.
- [ ] `final class` unless inheritance is explicitly needed.
- [ ] PHPDoc on the class and all public methods (`@param`, `@return`).
- [ ] All `array` parameters have generics in PHPDoc (`array<string,mixed>`, `string[]`, etc.).

## SQL & Database

- [ ] Use `DbUpsert::run()` for cross-driver upsert — do not write driver-specific SQL by hand.
- [ ] All amounts stored as **integer cents** (no floats, no DECIMAL in tests).
- [ ] Tokens/secrets stored as SHA-256 hash; plaintext returned to caller only at creation/rotation.
- [ ] SQLite-compatible SQL in production code (no MySQL-only functions in non-upsert paths).
- [ ] Queries use prepared statements with `?` placeholders; no raw string interpolation of user input.

## Tests

- [ ] Test file lives at `tests/Unit/Xion/ClassNameTest.php`.
- [ ] `setUp()` creates an in-memory SQLite database with the required table(s).
- [ ] Happy path, conflict/idempotent case, and edge cases (empty result, not-found) all covered.
- [ ] Date boundary tests annotate every literal date with `// +N days` comment.
- [ ] Phan suppression comments use the correct variant:
  - `PhanTypeMismatchArgumentNullableInternal` for PHP built-ins (`strlen`, etc.)
  - `PhanTypeMismatchArgumentNullable` for user-defined methods

## Static Analysis

- [ ] Run `composer analyze:file -- class/xion/NewClass.php tests/Unit/Xion/NewClassTest.php` before pushing.
- [ ] Zero new Phan errors (add suppressions with a comment explaining why, not silently).

## Docs & Tracking

- [ ] Class description added to `class/xion/INDEX.md` in the correct category.
- [ ] Schema columns added to `class/xion/SchemaDefinition.php`.
- [ ] `docs/todo/current.md` entry marked done.
- [ ] `docs/field-trials/candidates.md` archive entry added.
