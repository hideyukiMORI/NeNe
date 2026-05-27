# Field Trial 53 — Personal Data Export

**Date**: 2026-05-27
**Branch**: `feat/ft53-personal-data-export`
**Baseline**: `d3c6720` (post FT25–FT50 merge wave)

## Goal

Establish a GDPR Article 20-style personal data portability pattern for NeNe applications. Provide a dependency-free helper that aggregates user data from multiple tables into a portable JSON export.

## What was built

### `Nene\Func\PersonalDataExport` (`class/func/PersonalDataExport.php`)

Instance-based aggregator providing:

| Method | Description |
| --- | --- |
| `register(string $section, callable $provider): static` | Register a provider callable for a named section. Fluent — returns `$this`. |
| `export(int\|string $userId): array` | Run all providers, return `['exportedAt', 'userId', 'data']` envelope. |
| `exportJson(int\|string $userId, int $flags): string` | JSON-encode the export. Adds `JSON_THROW_ON_ERROR`; defaults to pretty + `JSON_UNESCAPED_UNICODE`. |
| `sections(): array` | Return registered section names in order (for tests). |

Key design points:

- **Instance-based**: each `PersonalDataExport` holds its own provider registry, easy to configure per-request or per-test.
- **Provider signature**: `callable(int|string $userId): array` — providers capture their own DB connections via closure; the aggregator has no infrastructure dependency.
- **Section ordering**: sections appear in registration order (FIFO).
- **Overwrite semantics**: re-registering the same section name replaces the previous provider.
- **Empty sections included**: providers returning `[]` still produce their key in the export, keeping the output schema predictable.
- **ISO 8601 timestamp**: `exportedAt` uses `DateTimeInterface::ATOM` for maximum interoperability.

### Tests (`tests/Unit/Func/PersonalDataExportTest.php`)

18 unit tests covering:

- Export envelope contains `exportedAt`, `userId`, `data`
- `exportedAt` is ATOM-format ISO 8601
- `userId` preserved as integer
- `userId` preserved as string (UUID)
- No providers → `data` is `[]`
- Single provider section present and populated
- Multiple providers, all sections present
- Sections appear in registration order
- Provider receives the correct user ID
- Re-registering a section overwrites the previous provider
- `register()` returns `$this` (fluent)
- Fluent chaining via method chain
- `sections()` returns registered names
- `sections()` returns `[]` when no providers
- `exportJson()` returns valid JSON
- `exportJson()` preserves Unicode characters (Japanese characters not escaped)
- `exportJson()` with custom flags (compact mode)
- Provider returning empty array — section still present

### Howto (`docs/development/personal-data-export.md`)

Covers: API table, provider registration pattern, output structure sample, custom JSON flags, provider signature, empty sections, test patterns without DB.

## Findings

### F-1 — No finding (clean trial)

The implementation required no framework changes. `PersonalDataExport` is a pure `Nene\Func` helper with no external dependencies or NeNe-core coupling. All 18 tests pass; CS fixer and Phan both clean.

## Decision

Merge as-is. No follow-up Issues raised.
