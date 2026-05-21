# Field Trial Follow-ups

This file consolidates findings from past field trial reports that were recorded as `defer` or `legacy-preserved` and were therefore **not filed as their own GitHub Issue**. Reports themselves are frozen at completion time, so this file is the durable index that future trials should check before recording the same friction.

Methodology reference: `docs/field-trials/README.md`. Decision authority: ADR 0002.

## How to Use This File

When a new trial surfaces friction that looks similar to an entry here:

1. Read the entry's *Re-evaluation trigger* line.
2. If the trigger condition is met (second occurrence, materially worse, or scope changed), escalate: file a focused GitHub Issue, remove the entry from this file, and reference the Issue in the next trial report.
3. If the trigger condition is not met, just add a back-reference from the new trial report's Friction Summary so the link between sightings stays visible.

Entries are grouped by trial of origin. Once an entry is escalated to an Issue and that Issue is merged or closed with a rationale, remove the entry from this file with a short note in the next trial report.

## From FT1 (2026-05-20)

Source: `2026-05-field-trial-1.md`.

### Host-side WSL setup (F-0a / F-0b / F-0c, consolidated)

- **Friction**: On WSL 2 with a stock Ubuntu image, three preconditions were necessary before any NeNe command could run: PHP unavailable at the target 8.4 version on default apt, Docker Desktop's WSL integration not enabled for the distro, and the user not in the `docker` group. Each was a one-action fix, but the NeNe Docker Quick Start did not flag any of them as prerequisites.
- **Decision in FT1**: `defer` (host-side, not NeNe's fault).
- **Re-evaluation trigger**: a second trial on a different WSL host (or on a non-WSL Linux host) re-discovers any of the three. Once confirmed as a pattern rather than a single host quirk, add a short "WSL Prerequisites" subsection to `docs/development/docker.md` covering all three preconditions.
- **ADR likely?**: No. This is documentation, not architecture.

## From FT2 (2026-05-21)

Source: `2026-05-field-trial-2.md`.

### F-2 — Soft-delete + unique constraint test isolation

- **Friction**: `tags.name` was marked `UNIQUE` while the table used soft delete (`is_deleted = 1`). The second test run could not recreate a tag with the same name because the soft-deleted row from the first run still held the unique key. FT2 fixed it in tests by namespacing every tag name with a per-run prefix; the framework convention is unspecified.
- **Decision in FT2**: `defer` (workaround applied inside the trial).
- **Re-evaluation trigger**: a future trial again hits a unique-constraint conflict caused by soft-deleted rows, or a new bundled sample adopts a unique column that needs the same workaround. At that point the choice is an ADR-class decision:
  - (a) keep `is_deleted` + `UNIQUE`, document the namespacing pattern for tests;
  - (b) drop soft delete on rarely-deleted lookup tables;
  - (c) replace the constraint with a partial unique index on `(name) WHERE is_deleted = 0` (SQLite) and `(name, is_deleted)` (MySQL) or equivalent.
- **ADR likely?**: Yes, if escalated.

### F-4 — `TransactionManager` reference implementation in `class/controller/`

- **Friction**: `Nene\Xion\TransactionManager` is documented in `docs/development/coding-standards.md` and demonstrated in `docs/tutorials/building-a-service.md`, but no controller in `class/controller/` actually uses it. Source-only search (grep for `TransactionManager` in `class/controller/`) returns nothing, so contributors who skip the tutorial may miss the pattern.
- **Decision in FT2**: `defer` (tutorial coverage exists; full reference implementation would be Issue #145 scope).
- **Re-evaluation trigger**: Issue #145 (small-service reference implementation) lands and continues to skip `TransactionManager`, OR a trial uncovers a contributor who reached for an ad-hoc PDO transaction because the canonical pattern was not findable.
- **ADR likely?**: No. The decision was already made (ADR-class behavior is in coding standards). This is a sample-implementation gap.

## From FT4 (2026-05-21)

Source: `2026-05-field-trial-4.md`.

### F-8 — `Initialize::init()` required when HTTP tests touch framework classes directly

- **Friction**: `tests/Http/NoteHtmlTest.php` cleanup helper wanted `\Nene\Xion\PdoConnection::getInstance()->exec(...)`. The call failed with `Undefined constant "Nene\Xion\DB_TYPE"` until `\Nene\Xion\Initialize::init()` was called in `setUp()`. Existing HTTP tests do not touch framework classes directly (they go through curl-style HTTP), so the requirement was not previously surfaced.
- **Decision in FT4**: `defer` (workaround applied inside the trial — explicit `init()` call in `setUp()`).
- **Re-evaluation trigger**: a future trial again needs to touch framework classes from within an HTTP test (cleanup helper, fixture setup, schema introspection, etc.) and re-discovers the requirement, OR `docs/development/testing.md` is rewritten and the gap can be closed there. Once confirmed as a recurring pattern, add a short "HTTP tests that need framework classes" subsection.
- **ADR likely?**: No. This is documentation, not architecture.

## Notes

- FT2 F-5 (OpenAPI per-error-code envelope boilerplate) was escalated in FT3 and resolved by PR #256, which adopted a single generic `ApiFailureEnvelope` per ADR 0003. The entry has been removed from this file.
- `legacy-preserved` findings (FT2 F-3 — URL parameter `key_value` format) were closed in PR #241 via documentation, not deferred, so they do not appear here. The `legacy-preserved` kind itself does not imply deferral; it means the fix is documentation rather than redesign.
- The longer this file becomes without entries getting escalated or removed, the more it suggests that NeNe's friction surface is stable. The shorter it stays, the more it suggests every trial is actionable.
