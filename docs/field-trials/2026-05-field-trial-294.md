# Field Trial 294 — Raffle

**Date**: 2026-05-29
**Branch**: `feat/ft294-raffle`
**Baseline**: post FT293 merge

## Goal

Add `Nene\Kit\Raffle` — ticket-based prize draw: collect entries per participant, then draw distinct winners weighted by ticket count. Distinct from `WeightedPicker` (FT277, stateless weighted pick over a configured pool): this accumulates real participant entries.

## What was built

### `Nene\Kit\Raffle` (`class/kit/Raffle.php`)

| Method | Description |
| --- | --- |
| `enter(raffle, participant, tickets=1)` | Add tickets (each a row; more tickets = higher chance). |
| `entryCount / ticketsFor / hasEntered / participants` | Counts / membership. |
| `draw(raffle, count=1, seed=null): array` | Distinct winners, ticket-weighted; seedable. |
| `clear(raffle): int` | Remove all entries. |

Key design points:

- **Ticket-weighted distinct draw**: Fisher–Yates shuffle of the ticket list, then take distinct participants in shuffle order (more tickets ⇒ likelier early ⇒ weighted), clamped to available participants.
- **Seedable** (`mt_srand($seed)`) for reproducible/audited draws; restores the RNG afterward.
- Entries added inside a transaction.

### Tests (`tests/Unit/Kit/RaffleTest.php`)

12 unit tests (20 assertions), property-based for the random parts: enter/counts, hasEntered/participants, draw returns an entrant, **distinct winners (no dupes)**, count clamps to participants, empty raffle → empty, **seeded draw deterministic**, raffle separation, clear, validation (zero tickets, empty participant, zero count).

## Findings

### F-1 — No finding (clean trial)

Clean `Nene\Kit` helper. 12 tests pass; CS Fixer and Phan clean.

## Decision

Merge as-is. No follow-up Issues raised.
