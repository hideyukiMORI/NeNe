# Field Trial 51 — i18n / Message Catalog

**Date**: 2026-05-27
**Branch**: `feat/ft51-i18n`
**Baseline**: `d3c6720` (post FT25–FT50 merge wave)

## Goal

Establish a lightweight, dependency-free i18n message-catalog pattern for NeNe applications.  
NENE2 対応: FT155 (i18nlog).

## What was built

### `Nene\Func\I18n` (`class/func/I18n.php`)

Static helper providing:

| Method | Description |
| --- | --- |
| `setLocale(string)` | Set the default locale. |
| `locale(): string` | Get the current default locale (`'en'` initially). |
| `load(string $locale, array $messages)` | Register/merge a key→message catalog. |
| `t(string $key, array $params, string $locale): string` | Translate with `{placeholder}` substitution, locale fallback, and key-as-last-resort. |
| `reset()` | Clear all state (for tests). |

**Placeholder syntax**: `{name}` in the message string is replaced by `$params['name']`.

**Fallback chain**: requested locale → default locale → key string itself.

### Tests (`tests/Unit/Func/I18nTest.php`)

19 unit tests covering:

- Default locale (`en`)
- `setLocale` / `locale`
- Simple key lookup and explicit locale override
- Single and multiple placeholders
- Repeated placeholder in same message
- Unknown placeholder left as-is
- Fallback to default locale when key missing
- Key returned as-is when not found anywhere
- `load` merging and last-writer-wins
- `reset` clears all state
- Empty message string
- Empty key
- Region-code locale (`zh-TW`)

### Howto (`docs/development/i18n.md`)

Covers: API table, `load` at bootstrap, placeholder syntax, fallback chain, locale naming conventions, test isolation with `reset()`, organising catalogs in separate files.

## Findings

### F-1 — No finding (clean trial)

The implementation required no framework changes. `I18n` is a pure `Nene\Func` helper with no external dependencies or NeNe-core coupling. All 19 tests pass; CS fixer and Phan both clean.

## Decision

Merge as-is. No follow-up Issues raised.
