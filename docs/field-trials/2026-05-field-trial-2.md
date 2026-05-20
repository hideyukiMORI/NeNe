# Field Trial 2 — bookmarklog (Bookmark + Tag M:N)

Methodology reference: `docs/field-trials/README.md`. Report skeleton: `docs/templates/field-trial-report.md`. Trial Issue: #234.

## Date

2026-05-21

## Baseline

- NeNe ref: `ccf6ac1` (Merge PR #233, FT1 report). Trial clone landed on the same `main` after PRs #223 / #228 / #229 / #230 / #231 were already merged.
- Clone path: `/home/xi/github/NeNe-FT/ft2-bookmark-tag/`
- Trial host: WSL 2 (Ubuntu 22.04) on Windows with Docker Desktop integration enabled and the user in the `docker` group (the environment friction recorded in FT1 was resolved before this trial).
- PHP: 8.4.21 (in `php:8.4-apache` container)
- Database: MySQL 8.4 (Docker Compose default), SQLite parity confirmed via `cli/initSQLite.php`
- Other tooling: PHPUnit 10.5.63, Composer 2.9.8, curl 8

### Baseline verification

The FT1 improvements landed cleanly in this trial's baseline:

| Check | Result |
| --- | --- |
| `docker compose run --rm app composer install` | clean, no `dubious ownership` warning (post #231) |
| `docker compose up -d app` | `/health` 200 OK in 5s, `databaseType: MySQL` |
| `composer test` | 43 / 43, 125 assertions |
| `composer test:http` with `NENE_HTTP_BASE_URL` set | 20 / 21, 211 assertions; 1 expected skip is `HttpErrorExposureTest` |
| `composer test:http` with env unset | preflight banner explains the skip clearly (post #229) |
| CI workflow on a sibling PR | `unit` + `HTTP runtime smoke (Docker)` both green (post #230) |

FT2 started from a known-good post-FT1 baseline. Findings recorded below are attributable to NeNe surfaces the trial actually exercised, not to setup hygiene.

## Goal

Exercise the NeNe surfaces the existing `User` / `Todo` sample does not cover:

1. M:N relation handling with a junction table.
2. Transaction boundary in a controller that updates a parent entity and re-syncs a many-to-many relation diff.
3. Path parameter extraction for `/{controller}/{action}/id_X` routes.
4. `{action}{Method}Rest` resolution across GET / POST / PATCH / DELETE.
5. `config/error_codes.php` catalog extension for two distinct error domains.
6. OpenAPI extension with two new entity tags, schemas, and request bodies.
7. SQLite ↔ MySQL parity for the new schema.

Authentication, CSRF, and HTML rendering remain explicitly out of scope.

## Service Built — bookmarklog

A REST-only sandbox for URL bookmarks with many-to-many tag relations.

### Schema (parallel changes in two locations)

`docker/mysql/init/001_schema.sql` and `cli/initSQLite.php` both received the same three tables:

- `bookmarks` (id, url, title, description, created_at, updated_at, is_deleted)
- `tags` (id, name UNIQUE, created_at, updated_at, is_deleted)
- `bookmark_tags` (bookmark_id, tag_id, created_at; composite PK; FKs with `ON DELETE CASCADE`)

The SQLite path additionally received `bookmarks_updated_at_trigger` and `tags_updated_at_trigger` to match MySQL's `ON UPDATE CURRENT_TIMESTAMP` semantics. The two files do not share a source of truth; see F-7.

### Endpoints

| Method | Path | Handler | Notes |
| --- | --- | --- | --- |
| GET | `/bookmark/index` | `indexGetRest` | List bookmarks. Optional `?tag=NAME` filter joins through the junction. |
| POST | `/bookmark/index` | `indexPostRest` | Create. `tag_ids` validated before opening the transaction (see F-1). |
| GET | `/bookmark/item/id_X` | `itemGetRest` | Detail incl. related tag list. |
| PATCH | `/bookmark/item/id_X` | `itemPatchRest` | Partial update. `tag_ids` (if present) replaces the relation set in one transaction. |
| DELETE | `/bookmark/item/id_X` | `itemDeleteRest` | Soft-delete the bookmark; junction rows are cleared explicitly first. |
| GET | `/tag/index` | `indexGetRest` | List active tags. |
| POST | `/tag/index` | `indexPostRest` | Create tag. Duplicate name returns `TAG-NAME-DUPLICATE`. |
| DELETE | `/tag/item/id_X` | `itemDeleteRest` | Soft-delete tag. |

Naming follows the existing `TodoController` convention (`index` for collection, `item` for single resource). The URL parameter format is `id_X` — see F-3.

### Error catalog

Eight new codes were added to `config/error_codes.php` (`BOOKMARK-ID-REQUIRED`, `BOOKMARK-NOT-FOUND`, `BOOKMARK-URL-REQUIRED`, `BOOKMARK-TITLE-REQUIRED`, `BOOKMARK-TAG-IDS-INVALID`, `TAG-ID-REQUIRED`, `TAG-NOT-FOUND`, `TAG-NAME-REQUIRED`, `TAG-NAME-DUPLICATE`).

### OpenAPI

`docs/api/openapi.yaml` grew to cover all eight new operations. Per-entity success envelopes (`BookmarkSummary`, `TagSummary`, `BookmarkSuccessEnvelope`, `BookmarkListSuccessEnvelope`, `BookmarkDeleteSuccessEnvelope`, plus the Tag equivalents) were added. Per-error-code envelopes were intentionally **not** added; the new endpoints reuse a single generic `ApiFailureEnvelope` schema. See F-5 for the rationale.

### Tests

A new `tests/Http/BookmarkTagTest.php` covers:

- Tag lifecycle (create, duplicate detection, soft-delete).
- Bookmark create / list / detail / partial update / tag relation replacement / soft delete.
- `?tag=` filter behavior.
- Validation failures (missing url, missing title, malformed tag_ids, unknown tag_ids).
- Soft-deleted tag is filtered from the bookmark's tag list.

Suite totals after FT2:

- `composer test`: 43 / 43, 125 assertions (no regression).
- `composer test:http`: 27 / 27, 243 assertions, 1 expected skip.

## Steps Taken

1. **Trial clone created** at `../NeNe-FT/ft2-bookmark-tag/`. No friction (FT1 closed all the setup gaps).
2. **Baseline runtime verified**. All FT1 improvements work as expected.
3. **Schema added in two files**. `cli/initSQLite.php` PHP + `docker/mysql/init/001_schema.sql` SQL. Verified both paths produce the same tables. Manual parity check; nothing in the framework enforces it.
4. **Models defined**: `Bookmark`, `Tag`. Both extend `DataModelBase`, follow the existing convention.
5. **Mappers written**: `BookmarkMapper`, `TagMapper` use the standard `DataMapperBase` shape. `BookmarkTagMapper` does NOT — composite PK breaks the `KEY_SID` assumption, so the junction mapper uses raw prepared statements throughout. See F-6.
6. **Controllers written**: `BookmarkController`, `TagController`. Both opt out of `SESSION_CHECK` in `preAction()` to match the trial's no-auth scope. `BookmarkController` uses `TransactionManager` for create/update/delete to keep relation diffs atomic.
7. **First smoke through curl revealed F-1.** The original create implementation called `assertTagsExist()` inside the transaction callback and threw `PDOException` for unknown ids. The exception bubbled past `TransactionManager::run()` to `htdocs/index.php`'s catch-all and surfaced as plain-text 500. The fix was to refactor `assertTagsExist` into `allTagsExist`, run it **before** opening the transaction, and return a proper `BOOKMARK-TAG-IDS-INVALID` failure when validation fails.
8. **HTTP tests added** and revealed F-2. The first run succeeded; the second run failed with `Duplicate entry 'php' for key 'tags.tags_name_unique'` because soft-deleted tags from the first run still own the name. Fixed by namespacing every tag name in this suite with a per-run prefix (`ft2-<random>-...`) so cross-run pollution does not break uniqueness. The pre-existing `TodoTest` does not hit this because `todos.title` is not unique.
9. **OpenAPI extended**. Adding the eight operations and their schemas took ~250 lines. The per-error-code envelope pattern used by the existing TODO contract would have required seven more envelope schemas; FT2 used a single generic `ApiFailureEnvelope` instead to keep the scope manageable and to surface the boilerplate cost. See F-5.
10. **Suite green** under both unit (43 / 43, 125) and HTTP (27 / 27, 243, 1 expected skip).

## Results

| Scenario | Expectation | Actual | Status |
| --- | --- | --- | --- |
| Two-file schema add (SQLite + MySQL) | Schema applies, `/health` shows healthy | both paths produce identical tables | Pass (with F-7) |
| Junction-table mapper on `DataMapperBase` | Reuse existing helpers | Composite PK forced raw SQL throughout | F-6 |
| `TransactionManager` for bookmark create/update with M:N diff | Atomic commit/rollback | Works after F-1 refactor | Pass (with F-1) |
| `/bookmark/index?tag=NAME` filter | Returns only bookmarks with the tag | Works | Pass |
| `PATCH /bookmark/item/id_X` with `tag_ids` only | Replaces relation, preserves bookmark fields | Works | Pass |
| `PATCH` with `tag_ids: []` | Clears all relations | Works | Pass |
| `DELETE` cascades junction (soft-delete path) | Junction emptied explicitly before bookmark soft-delete | Works (manual `clearTagsForBookmark` in the same TX) | Pass |
| Tag soft-delete + filter from bookmark detail | Soft-deleted tag disappears from `bookmark.tags[]` | Works (JOIN filter on `is_deleted = 0`) | Pass |
| HTTP test re-run | Suite passes second time | Failed first; fixed by per-run namespacing | F-2 |
| `composer test:http` with smoke | 26 of 27 pass, 1 expected skip | Final: 27 / 27, 243 assertions, 1 skip | Pass |
| `composer test` regression | 43 / 43 still | Yes | Pass |

## Friction Summary

| ID | Location | Severity | Kind | Decision |
| --- | --- | --- | --- | --- |
| F-1 | `class/xion/TransactionManager.php` + `htdocs/index.php` boundary | medium | feature-gap | fix-in-framework |
| F-2 | Soft-delete + unique constraint interaction; no test isolation pattern | medium | design-trade-off | document |
| F-3 | `class/xion/UrlParameter.php` convention not surfaced in routing docs | low | legacy-preserved | document |
| F-4 | `TransactionManager` shown in tutorial/coding-standards but not in `class/controller/` | low | docs-gap | document |
| F-5 | OpenAPI per-error-code envelope boilerplate | low | feature-gap | defer |
| F-6 | `DataMapperBase` does not cover composite-PK junction tables | medium | design-trade-off | document |
| F-7 | SQLite (`cli/initSQLite.php`) and MySQL (`docker/mysql/init/001_schema.sql`) schema files maintained in parallel | low | docs-gap | document |

## Recommendations

### Immediate (documentation only)

1. **F-3** — Add a short "URL parameter format" subsection under `docs/development/coding-standards.md` (or a new `docs/development/url-parameters.md`) explaining that NeNe routes use `key_value` segments, with a small example contrasting the convention against REST `{id}` placeholders so AI agents do not regenerate the wrong shape. Cross-link from `tutorials/building-a-service.md`.
2. **F-7** — Add a short note next to either schema file (or in `docs/development/docker.md`) documenting the parallel-maintenance expectation. Optional: a future ADR could discuss consolidating to a shared schema source, but a one-paragraph note is the minimum to prevent silent drift.

### Suggested (small framework or template change)

3. **F-1** — Introduce a domain-error escape inside `TransactionManager::run()`. One concrete shape: a `DomainException` subclass that `htdocs/index.php` catches and converts to `ApiResponse::failure($code)` without leaking to plain text 500. Today the only safe pattern is "validate outside the transaction", which FT2 demonstrated but is not documented anywhere.
4. **F-6** — Add a short reference in `docs/development/coding-standards.md` (Data mapper section) explicitly stating that junction tables use raw prepared statements rather than `DataMapperBase`'s id-centric helpers. `BookmarkTagMapper` from this trial can be linked as a future reference if it later lands in the sample.

### Trade-offs (needs ADR or discussion)

5. **F-2** — Three options: (a) keep `tags.name` unique and document the soft-delete + uniqueness friction with a recommended namespacing pattern for tests; (b) drop `is_deleted` on rarely-deleted lookup tables like `tags` and use hard deletes; (c) move uniqueness to `(name, is_deleted)`. FT2 picked (a) as a workaround inside the trial, but the canonical NeNe stance is undocumented.
6. **F-5** — Per-error-code OpenAPI envelopes are exhaustive and reviewable, but each new entity needs N envelope schemas. A generic `ApiFailureEnvelope` (used by FT2) is more compact but loses the per-code documentation. An ADR could decide whether new contracts adopt the generic shape going forward.

## Overall Impression

FT2 confirmed that NeNe's stable conventions transfer cleanly to a new two-entity service. The route-resolution shape (`/{controller}/{action}/id_X` → `{action}{Method}Rest`), `DataMapperBase` reuse, error-code catalog, and Smarty-less REST path were all immediately usable. The two-day-old PR #230 (CI runtime smoke) would have caught the first F-1 reproduction had it been on `main` when #213 was merged — that change is already paying back.

The friction surface this trial exposed is narrower than FT1's. Most findings are documentation or small-helper class. The only one that is non-trivial is F-1: throwing inside `TransactionManager::run()` reveals that NeNe does not have a first-class "domain error from inside a transaction" path. The workaround (validate outside, transaction inside) is acceptable but should be explicit.

The OpenAPI cost is higher than expected. For two new entities, the existing per-error-code envelope pattern would have meant seven new envelope schemas of boilerplate. FT2 made an executive call to use a generic `ApiFailureEnvelope` instead. The right long-term answer is probably an ADR — either the existing pattern stays canonical and the doc explains the boilerplate cost is the price of strict response shape verification, or the generic shape becomes the canonical pattern and the existing per-code envelopes get refactored.

Two things FT2 did not catch but which I expected: H-D (SQLite schema convention), H-F (error-code catalog scaling), H-H (cascade response shape), H-I (filter query parsing). Each turned out to be either obvious enough (H-D) or specified well enough (the others) that no friction surfaced. That is encouraging — NeNe's conventions held up across more surface than the trial originally hypothesized.

The Bookmark + Tag implementation is also a reasonable candidate for a future bundled sample. It exercises one M:N relation, transactional diff, OpenAPI extension, and dual DB setup — all useful as a teaching example. FT2 did not commit it back to the framework, but future work could consider promoting it as a second reference service.

## Follow-up Issues

To be filed under the FT2 loop (close all before starting FT3):

- F-1 (medium, framework): TransactionManager + domain error path.
- F-3 (low, docs): URL parameter `key_value` convention documentation.
- F-6 (medium, docs): Junction-table guidance in coding standards.
- F-7 (low, docs): SQLite/MySQL schema parity note.

F-2 (test isolation), F-4 (TransactionManager reference is partially covered already), and F-5 (OpenAPI envelope boilerplate) are deferred. They are recorded in the table above; if a future FT re-surfaces them, escalate.

## Reminder

This report omits secrets, raw API keys, production endpoints, and confidential prompts.
