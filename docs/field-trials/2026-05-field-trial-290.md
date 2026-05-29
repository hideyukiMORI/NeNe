# Field Trial 290 — ShippingZone

**Date**: 2026-05-29
**Branch**: `feat/ft290-shipping-zone`
**Baseline**: post FT289 merge

## Goal

Add `Nene\Kit\ShippingZone` — region → shipping-zone rate lookup with integer-cent rates and an optional free-shipping threshold. Complements `TaxRate` (FT207) for the shipping side of checkout.

## What was built

### `Nene\Kit\ShippingZone` (`class/kit/ShippingZone.php`)

| Method | Description |
| --- | --- |
| `setRate(region, zone, rateCents, freeOverCents=0)` | Upsert per region. |
| `rateFor(region, orderCents=null): ?int` | Cost (0 if free, null if unknown). |
| `zoneOf(region) / regionsIn(zone) / zones()` | Zone lookups. |
| `remove(region)` | Delete. |

Key design points:

- **Integer-cent** rates (no floats); **free-shipping threshold** is inclusive (`orderCents >= freeOver` → 0); threshold ignored when no order total is given or when 0.
- Region-keyed (cross-driver upsert), same shape as `TaxRate`.

### Tests (`tests/Unit/Kit/ShippingZoneTest.php`)

12 unit tests (18 assertions): set/rate, unknown→null, free threshold (under / exactly-at / over / no-total), no-threshold always charges, zoneOf, regionsIn, distinct zones, idempotent replace, remove (+ missing no-op), validation (negative, empty region).

## Findings

### F-1 — No finding (clean trial)

Clean `Nene\Kit` helper. 12 tests pass; CS Fixer and Phan clean.

## Decision

Merge as-is. No follow-up Issues raised.
