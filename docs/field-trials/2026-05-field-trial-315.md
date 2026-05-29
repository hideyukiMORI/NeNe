# Field Trial 315 — SeatMap

**Date**: 2026-05-29
**Branch**: `feat/ft315-seatmap`
**Baseline**: post FT314 merge

## Goal

Add `Nene\Kit\SeatMap` — named-seat reservation for a fixed venue layout: define seats (e.g. "A1".."A10"), reserve a *specific* seat for a holder, release it, and query who holds what. Distinct from `EventTicket` (a capacity count with check-in, no seat identity), `ResourceReservation` (time-bounded, single shared resource), and `TimeSlot` (appointment booking).

## What was built

### `Nene\Kit\SeatMap` (`class/kit/SeatMap.php`)

| Method | Description |
| --- | --- |
| `addSeat(venue, seat)` / `addRow(venue, row, count)` | Define seats (idempotent; `addRow` makes `row.1`..`row.count`). |
| `reserve(venue, seat, holder): bool` | Claim a free seat; false if taken. |
| `release(venue, seat): bool` | Free it; false if already free. |
| `holderOf / isAvailable` | Per-seat reads. |
| `availableSeats / reservedSeats / seatsOf` | Venue/holder listings. |

Key design points:

- **Race-free claim**: `reserve()` is a single guarded `UPDATE … WHERE … AND holder IS NULL` (`rowCount() > 0` = won the seat); two holders can never claim the same seat.
- **Idempotent seat definition** via `ON CONFLICT DO NOTHING`, so re-adding a seat never wipes its holder.
- **Reserve targets a real seat**: reserving a nonexistent seat throws; reads of unknown seats are safe.

## Findings

### F-1 — Seat names sort lexically

`availableSeats`/`seatsOf` order by the seat column, so "A10" sorts before "A2" lexically. For human-natural ordering with >9 seats per row, zero-pad seat numbers ("A01"). Documented; the helper deliberately stays storage-order-agnostic rather than embedding a numeric parser. Tests use a 5-seat row where lexical and numeric order coincide.

## Decision

Merge as-is. No follow-up Issues raised.
