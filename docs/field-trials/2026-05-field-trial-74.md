# Field Trial 74 — Geo / Location Helper

**Date**: 2026-05-27
**Branch**: `feat/ft74-geo-helper`
**Baseline**: post FT73 merge

## Goal

Establish a geographic distance calculation pattern for NeNe applications. Provide Haversine-formula distance and bounding-box proximity search as a pure static helper — no DB or framework dependency.

## What was built

### `Nene\Func\GeoHelper` (`class/func/GeoHelper.php`)

Pure static geo helper providing:

| Method | Description |
| --- | --- |
| `distanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float` | Great-circle distance in km. |
| `distanceMi(float $lat1, float $lon1, float $lat2, float $lon2): float` | Great-circle distance in miles. |
| `boundingBox(float $lat, float $lon, float $radiusKm): array{minLat,maxLat,minLon,maxLon}` | Rectangular pre-filter box. |

Key design points:

- **Haversine formula**: accurate great-circle distance on a sphere (Earth = 6371 km).
- **Static helper**: no state, no DB — pure math.
- **Bounding box**: rectangular pre-filter for SQL `BETWEEN` queries; combine with exact distance for accuracy.
- **Boundary clamping**: lat clamped to ±90, lon clamped to ±180.
- **Zero dependencies**: standard PHP math functions only.

### Tests (`tests/Unit/Func/GeoHelperTest.php`)

12 unit tests covering:

- distanceKm same point is zero
- distanceKm Tokyo→Osaka ≈ 396 km
- distanceKm is symmetric
- distanceMi same point is zero
- distanceMi is less than km
- distanceMi conversion ratio (~0.621371)
- boundingBox returns all keys
- boundingBox centre is inside box
- boundingBox larger radius gives larger box
- boundingBox clamps to valid range (pole edge case)
- boundingBox zero radius throws
- boundingBox negative radius throws

### Howto (`docs/development/geo-helper.md`)

Covers: API table, usage examples, bounding box pattern diagram, Haversine formula, key design points.

## Findings

### F-1 — No finding (clean trial)

`GeoHelper` is a clean `Nene\Func` static helper. 12 tests pass; CS Fixer and Phan clean.

## Decision

Merge as-is. No follow-up Issues raised.
