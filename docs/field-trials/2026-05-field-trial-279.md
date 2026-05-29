# Field Trial 279 — RedactionRule

**Date**: 2026-05-29
**Branch**: `feat/ft279-redaction-rule`
**Baseline**: post FT278 merge

## Goal

Add `Nene\Xion\RedactionRule` — a registry of named regex masking rules applied to text before it is logged, shown to support, or exported (mask card numbers, emails, tokens).

## What was built

### `Nene\Xion\RedactionRule` (`class/xion/RedactionRule.php`)

| Method | Description |
| --- | --- |
| `addRule(name, pattern, replacement='[REDACTED]', priority=0)` | Upsert; pattern validated for compilability. |
| `redact(text): string` | Apply all enabled rules in priority order. |
| `applyRule(name, text): string` | Apply one rule (ignores enabled flag). |
| `enable / disable / remove (name)` | Toggle / delete. |
| `rules()` | List in application order. |

Key design points:

- **Operator-supplied PCRE patterns** (config, not user input), validated at add-time via `@preg_match($pattern, $subject)` — the subject is a variable to keep Phan's arg-order check happy.
- **Priority then name ordering** so specific rules can run before broad ones.
- **`preg_replace` null-guarded**: on a runtime regex failure the text passes through unchanged rather than becoming null.
- **Cross-driver upsert**; **PDO injection**.

### Tests (`tests/Unit/Xion/RedactionRuleTest.php`)

13 unit tests (14 assertions): single + multiple rule redaction; priority ordering (broad-after-specific); disabled not applied; re-enable; applyRule ignores enabled flag + missing returns unchanged; idempotent add; rules ordered by priority then name; remove; no-rules passthrough; validation (invalid pattern, empty name).

## Findings

### F-1 — process note: `preg_match` arg-order

`@preg_match($pattern, '')` tripped `PhanParamSuspiciousOrder` (variable pattern + literal subject). Resolved cleanly by binding the subject to a variable — no suppression needed. Worth remembering for other regex-validation helpers.

## Decision

Merge as-is. No follow-up Issues raised.
