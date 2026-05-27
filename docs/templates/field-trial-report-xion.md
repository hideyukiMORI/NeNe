# Field Trial {N} — {ClassName}

**Date**: {YYYY-MM-DD}
**Branch**: `feat/ft{N}-{topic}`
**Baseline**: post FT{N-1} merge

## Goal

Add `Nene\Xion\{ClassName}` — {one-sentence description of what the class does}.

## What was built

### `Nene\Xion\{ClassName}` (`class/xion/{ClassName}.php`)

{One or two sentences on the design intent.}

| Method | Description |
| --- | --- |
| `method(args): return` | What it does. |

Key design points:

- **{Point 1}**: {explanation}.
- **PDO injection**: `__construct(private readonly ?PDO $db = null)`.

### Tests (`tests/Unit/Xion/{ClassName}Test.php`)

{N} unit tests covering:

- {test method description}
- {test method description}

### Howto (`docs/development/{topic}.md`)

Covers: {list main sections}.

## Findings

### F-1 — No finding (clean trial)

`{ClassName}` is a clean `Nene\Xion` helper. {N} tests pass; CS Fixer and Phan clean.

<!--
If friction was found, replace F-1 with one section per finding:

### F-1 — {Short title}

**Kind**: docs-gap | feature-gap | design-trade-off | legacy-preserved | process-gap
**Severity**: high | medium | low
**Decision**: fix-in-framework | document | keep-legacy | defer

{Description of what happened and why it matters.}
-->

## Decision

Merge as-is. No follow-up Issues raised.

<!--
If follow-up Issues were opened:

Opened follow-up Issues: #{issue1}, #{issue2}.
-->
