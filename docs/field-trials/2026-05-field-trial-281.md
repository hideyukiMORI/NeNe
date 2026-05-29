# Field Trial 281 — FeatureTour

**Date**: 2026-05-29
**Branch**: `feat/ft281-feature-tour`
**Baseline**: post FT280 merge

## Goal

Add `Nene\Xion\FeatureTour` — per-user state for one-time UI tours / coachmarks, so the front-end shows each guided tour only when appropriate.

> **Process note (dup avoided):** the originally-queued FT281 *TermsAcceptance* was dropped — `TermConsent` already covers versioned ToS/privacy acceptance. The name-only duplicate check had missed the conceptual overlap; pivoted to FeatureTour after a concept-scan of the INDEX. Lesson folded into the wave: scan INDEX *descriptions*, not just class names.

## What was built

### `Nene\Xion\FeatureTour` (`class/xion/FeatureTour.php`)

| Method | Description |
| --- | --- |
| `shouldShow(userId, tour): bool` | True when pristine (no prior interaction). |
| `markSeen / advance(step)` | Begin / record progress (status `seen`). |
| `complete / dismiss (userId, tour)` | Terminal states. |
| `status / step (userId, tour)` | Read state. |
| `reset(userId, tour) / resetAll(tour): int` | Re-show to one / everyone. |
| `completedCount / dismissedCount (tour)` | Analytics. |

Key design points:

- **Pristine = auto-show**: `shouldShow` is true only when no row exists; any interaction stops auto-display.
- **No status regression**: `markSeen`/`advance` will not pull a `completed`/`dismissed` tour back to `seen` (status compare inside the upsert transaction).
- **`resetAll` re-shows after a rewrite**; per-user `reset` resumes for one.
- **Cross-driver upsert**; **PDO injection**.

### Tests (`tests/Unit/Xion/FeatureTourTest.php`)

14 unit tests (23 assertions): pristine shouldShow; markSeen stops display; advance records step + creates record; complete/dismiss; **no regression from completed/dismissed**; reset; resetAll (re-show all, scoped to tour); counts by status; per-user separation; validation (negative step, empty tour).

## Findings

### F-1 — process-gap: concept-level duplicate slipped past name check

**Kind**: process-gap · **Severity**: low · **Decision**: document

Name-only dedup let a conceptual duplicate (TermsAcceptance vs existing TermConsent) into the queue. Caught before any PR by scanning INDEX descriptions. Mitigation applied to the rest of the wave: concept-scan, not just name-scan.

## Decision

Merge as-is. No follow-up Issues raised.
