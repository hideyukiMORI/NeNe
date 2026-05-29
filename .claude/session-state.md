# Session State — 2026-05-29

## Current Branch
`main` — FT265 merged and up to date.

## This session
1. **Repo cleanup** — removed 28 stale git worktrees (`.claude/worktrees/`, leftover
   parallel-agent run, pid 40898 dead) and 233 merged/superseded local branches.
   Only `feat/ft112-leader-board` was intentionally kept earlier, then the FT265
   branches were cleaned after merge. Remotes untouched.
2. **FT265 — SequenceNumber** ✅ shipped:
   - **PR #717** (feat) — `class/xion/SequenceNumber.php` + 20 tests; INDEX (Infrastructure & DB);
     report `docs/field-trials/2026-05-field-trial-265.md`.
   - **PR #718** (docs) — `composer ft:done` output (current.md / roadmap.md / candidates.md → FT1–FT265).
   - Both auto-merged (squash) after CI green.

## FT265 F-1 follow-up — scaffold bug FIXED ✅ (PR #719)
`composer make:xion` template (`tools/make-xion.php`) had two correctness bugs,
both fixed in **PR #719**:
- removed the wrong `use Nene\Database\PdoConnection;` (PdoConnection is
  `Nene\Xion`); production `$db === null` would have fatal'd + full Phan flagged it.
- corrected test stub namespace `Tests\Unit\Xion` → `Nene\Tests\Unit\Xion`
  (PSR-4-autoloadable; bootstrap maps `Nene\Tests\` → `tests/`).
Newly scaffolded classes are now namespace- and Phan-clean out of the box.

## FT266 — BusinessCalendar ✅ (this session)
Working-day calendar (weekend + per-calKey holidays) for SLA / due-date math.
`isBusinessDay` / `addBusinessDays` (±N, skips weekends+holidays) /
`businessDaysBetween` (half-open `[from,to)`) / `next`+`previousBusinessDay` /
`addHoliday`+`removeHoliday`+`holidays`. Round-trip date validation. 24 tests.
- **PR #720** (feat) + **PR #721** (docs), both auto-merged after CI green.
- Clean trial, no findings; FT265 scaffold fix (#719) held.

## 🏷️ v0.3.0 RELEASED (2026-05-29)
Tag `v0.3.0` + GitHub Release created; `VERSION` 0.2.0→0.3.0; `docs/releases.md`
notes added. Bundled 356 PRs since v0.2.0 (Xion catalogue FT1–FT287, DX tooling,
ADR-0003–0013, prod-readiness). Tagging cadence going forward: per ~10-FT docs
wave or monthly, not per-FT; VERSION bump tied to the release-prep PR.

## ✅ ADR-0014 — Xion core vs Kit helper split (DONE)
Executed across 3 PRs: #747 (Files&Media pilot), #748 (remaining 217), #749
(tooling+catalogue+docs). **Final: `class/xion/` = 55 framework-core, `class/kit/`
= 227 `Nene\Kit` helpers.** `tools/migrate-to-kit.php` (preflight+move) drove it.
- **FT288+ now scaffold with `composer make:kit -- Name`** (→ `Nene\Kit`,
  `class/kit/`, test ns `Nene\Tests\Unit\Kit`, auto `use Nene\Xion\PdoConnection;`).
  `make:xion` is for rare framework-core additions only.
- Indexes: `composer kit:index` (helpers) / `composer xion:index` (core).
- Dedup: concept-scan `class/kit/INDEX.md` descriptions before new helpers.
- STAY allowlist (what stayed core) is embedded in `tools/migrate-to-kit.php`.

## 🔁 AUTONOMOUS WAVE (paused at 21/50) — user asked to "run ~FT50 continuously"
Running FT267→~FT316 self-driven. Workflow per FT: scaffold → implement →
tests → report → precommit → feat PR → auto-merge → sync. **Docs (`ft:done`)
are BATCHED every 10 FTs into one wave PR** (not per-FT) to cut PR/CI count.

**Batch 1 DONE (10/50):** FT267–FT276 code + docs all merged. Docs wave PR #732
advanced current.md/roadmap to **FT1–FT276 complete**. Branches cleaned.
Classes: ExchangeRate, DataRetention, MaintenanceWindow, EmailSuppression,
PasswordPolicy, PercentageRollout, Heartbeat, DeadLetterQueue, RetrySchedule,
RoundRobinAssigner.

**Batch 2 DONE (20/50):** FT277–286 code + docs all merged (docs wave PR #743).
WeightedPicker, TermGlossary, RedactionRule, IpReputation, FeatureTour,
AffiliateClick, UtmCampaign, FunnelStep, Endorsement, PinnedItem.
current.md/roadmap now at **FT1–FT286 complete**.

**Batch 3 DONE (30/50):** FT287–296 code + docs all merged (docs wave #761).
ChecksumRegistry, QuietHours, Snooze, ShippingZone, Payout, QuizAttempt,
ReportSchedule, Raffle, PurchaseLimit, PriceAlert. current.md at **FT1–FT296**.
FT288+ all scaffolded via `composer make:kit` into `Nene\Kit` — workflow proven.

**Batch 4 DONE (40/50):** FT297–306 code + docs all merged (docs wave #772).
StockTransfer, BulkDiscount, Petition, ServiceStatus, Pseudonymizer, DailyReward,
Achievement, QueueTicket, Annotation, GiftRegistry. current.md at **FT1–FT306**.

**Batch 5 DONE (50/50) ✅ — WAVE COMPLETE.** FT307–316 code + docs all merged
(docs wave PR #783). current.md/roadmap now at **FT1–FT316 complete**.
Classes: LeaveRequest, ExpenseClaim, Kudos, Tournament, Dispute, PledgeDrive,
ShiftRoster, SpaceOccupancy, SeatMap, Escalation (PRs #773–#782).
- **FT314 pivot:** PunchCard dropped as a concept-dup of `TimeEntry`
  (start/stop/duration) → built **SpaceOccupancy** (capacity-enforced live
  headcount) instead. The FT281 dedup discipline working as intended.
- **FT313 finding:** `Nene\Kit` helpers using `DbUpsert` must
  `use Nene\Xion\DbUpsert;` (the scaffold only imports PdoConnection).

**⚠️ DEDUP LESSON:** name-only checks miss concept dups. Originally-queued
TermsAcceptance was dropped (duplicated existing **TermConsent**); CookieConsent
dropped (overlaps **ConsentLog**); AttributionTouch/AnnouncementRead dropped
(overlap Referral / ReadProgress+Announcement). ALWAYS concept-scan INDEX
descriptions (`grep -i <keyword> class/xion/INDEX.md`) before starting a class.

**Batch 3 candidates (re-verify each):** PinnedItem done→286; then ChecksumRegistry
(integrity), plus generate ~9 more clearly-novel for FT287–316.

## FT status
- **FT1–FT316 complete & docs-recorded.** The 50-trial autonomous `Nene\Kit`
  wave (FT267–316) is finished; markers in current.md/roadmap.md at FT1–FT316.
- No wave in progress. Next FT work is on-request only.
- Trigger-based candidates (FT36 background jobs, OTel, multi-tenancy) remain
  parked in `docs/field-trials/candidates.md` — do NOT pre-implement.

## FT workflow (FT288+ — Nene\Kit)
1. Concept-scan `class/kit/INDEX.md` descriptions for dups, THEN `composer make:kit -- ClassName`
2. Implement class + tests (if it uses `DbUpsert`, add `use Nene\Xion\DbUpsert;` — see FT313)
3. `composer kit:index` to refresh desc, then move the orphan INDEX row into the right section alphabetically (strip the trailing orphan with `perl -0pi -e`)
4. Write `docs/field-trials/2026-05-field-trial-<N>.md`
5. `composer precommit`
6. Push + PR (feat) + auto-merge; wait CI (rerun --failed on Docker Hub flake)
7. Sync main, delete merged branch
8. Docs (`composer ft:done -- FT<N> ClassName "desc" <PR#>`) BATCHED every ~10 FTs into one wave PR

## Nothing uncommitted
All work committed and merged.
