# Field Trial 283 — UtmCampaign

**Date**: 2026-05-29
**Branch**: `feat/ft283-utm-campaign`
**Baseline**: post FT282 merge

## Goal

Add `Nene\Xion\UtmCampaign` — capture UTM marketing parameters per visit for campaign attribution and first/last-touch analysis. Distinct from `AffiliateClick` (FT282) and `Referral` (FT85).

## What was built

### `Nene\Xion\UtmCampaign` (`class/xion/UtmCampaign.php`)

| Method | Description |
| --- | --- |
| `record(visitor, params[], asOf=null): int` | Store a UTM touch (source required). |
| `touchesFor(visitor)` | Full attribution path, oldest first. |
| `firstTouch / lastTouch (visitor)` | Acquisition / most recent touch. |
| `campaignTouches(campaign)` | Touch count for a campaign. |
| `countBy(field)` | Group counts by source/medium/campaign/term/content. |
| `purgeOlderThan(days, asOf=null)` | Housekeeping. |

Key design points:

- **First/last touch** derived by `id ASC/DESC` — supports both common attribution models.
- **`countBy` field whitelist**: the grouped column is validated against a fixed field list before interpolation (the rest is parameter-bound), so it is injection-safe.
- **Optional UTM fields default to `''`**; only `source` is required.
- **PDO injection**; `asOf` for deterministic time.

### Tests (`tests/Unit/Xion/UtmCampaignTest.php`)

12 unit tests (22 assertions): record + read, first/last touch, edge-null when none, oldest-first ordering, optional fields default empty, campaignTouches, countBy busiest-first, **countBy rejects unknown field (`evil; DROP TABLE`)**, visitor separation, purgeOlderThan, validation (empty visitor, empty source).

## Findings

### F-1 — No finding (clean trial)

Clean `Nene\Xion` helper. 12 tests pass; CS Fixer and Phan clean.

## Decision

Merge as-is. No follow-up Issues raised.
