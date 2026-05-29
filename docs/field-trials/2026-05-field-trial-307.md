# Field Trial 307 — LeaveRequest

**Date**: 2026-05-29
**Branch**: `feat/ft307-leave-request`
**Baseline**: post FT306 merge — **batch 5 (final 10) begins**

## Goal

Add `Nene\Kit\LeaveRequest` — employee time-off requests with an approve/reject workflow and approved-day accounting. Distinct from `Approval` (FT, generic single-approver): carries leave-specific fields and day totals.

## What was built

### `Nene\Kit\LeaveRequest` (`class/kit/LeaveRequest.php`)

| Method | Description |
| --- | --- |
| `request(employee, type, start, end, days): int` | Submit (pending). |
| `approve(id) / reject(id): bool` | Pending → approved/rejected (guarded). |
| `status(id) / requestsFor(employee, status=null)` | Read. |
| `approvedDays(employee, type=null)` | Sum approved days. |
| `pending()` | Approver inbox. |

Key design points:

- **Status guard** in the WHERE clause: approve/reject only act on pending rows (no double-decision); returns whether a row changed.
- Day count supplied by caller (pair with `BusinessCalendar` FT266); dates validated (`Y-m-d`, end >= start).
- `approvedDays` sums only approved rows, optionally by type.

### Tests (`tests/Unit/Kit/LeaveRequestTest.php`)

12 unit tests (24 assertions): pending on submit, approve + approvedDays, reject (not counted), no double-decision, approvedDays by type, requestsFor by status, pending inbox, unknown null, validation (end<start, zero days, bad date, empty employee).

## Findings

### F-1 — No finding (clean trial)

Clean `Nene\Kit` helper. 12 tests pass; CS Fixer and Phan clean.

## Decision

Merge as-is. No follow-up Issues raised.
