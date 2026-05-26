# Field Trial Candidates

Durable backlog of trial themes worth running, with notes on why each one matters and what's blocking it. Pair with `docs/field-trials/README.md` (methodology) and `docs/field-trials/follow-ups.md` (deferred findings).

This file is for **forward-looking** ideas — once a trial fires, its record moves to a dated `docs/field-trials/YYYY-MM-field-trial-N.md` report and the candidate row gets struck through or removed.

## How to use this file

- **Maintainers**: scan this list when choosing the next trial. Pick whatever fits the current bandwidth + curiosity.
- **AI agents**: when the user asks "next trial を提案して", use this list as the primary source rather than re-deriving candidates from memory. Add new candidates here whenever a session surfaces one.
- **Trim regularly**: stale candidates (≥6 months old without being picked up, or rendered moot by other work) are removed by whichever session notices them.

## Active candidates

### Real-app surface (FT12 / FT13 / FT16 系譜)

#### ~~Multi-instance session backend (Redis / DB-backed)~~ → **FT18 complete** (2026-05-26)

#### Background jobs / async work
**Why**: Mail sending, file processing, periodic cleanup all block requests today. Real-app gap. Commercial feasibility report flagged.
**Trigger**: A real app surfaces "POST /foo takes 8 seconds because it sends 3 emails" friction.
**Open design questions**: symfony/messenger vs DB queue vs lightweight cron. ADR-class.
**Size**: large.

#### Multi-tenancy
**Why**: `users` table is single-tenant. B2B SaaS deploys need row-scoped tenant isolation.
**Trigger**: Real deploy asks for it. **Don't pre-design** — overengineering risk is high without a concrete user.
**Size**: ADR + large.

### Observability lane (FT15 系譜)

#### ~~structured-logs (JSON formatter swap)~~ → **FT19 complete** (2026-05-27)

#### ~~Server-Timing header~~ → **FT20 complete** (2026-05-27)

#### OpenTelemetry traceparent / tracestate
**Why**: Industry-standard distributed tracing. Currently `X-Request-ID` (NeNe-flavored) only.
**Trigger**: Real deploy integrates an OTel collector. **Don't pre-implement** — OTel's spec is large and pre-deploy work usually overfits.
**Size**: ADR + medium.

### Structural / governance (FT11 / FT14 系譜)

#### ~~CLI command framework~~ → **FT24 complete** (2026-05-27)

#### ~~PHP version policy ADR~~ → **ADR-0012 complete** (2026-05-27)

#### ~~Smarty selection ADR~~ → **ADR-0011 complete** (2026-05-27)

#### Constraint-changes ADR (unique / FK additions)
**Why**: ADR-0009 explicitly left constraint changes in the "warning-only" path. If real deploys hit this pattern often, escalate to ADR.
**Trigger**: 3+ operator stories of "adding a UNIQUE constraint was painful".
**Size**: ADR + medium implementation.

### Meta / evaluation (Phase 6 系譜)

#### ~~ai-agent-journey~~ → **FT22 complete** (2026-05-27)

#### docs-journey-newcomer
**Why**: Similar to ai-agent-journey but human-centered. Trial with a developer who has never seen NeNe.
**Trigger**: A real candidate volunteer appears. Hard to invent the volunteer.
**Size**: medium, time-boxed by the volunteer's availability.

### Quality / maintenance

#### ~~static-analysis-baseline cleanup~~ → **complete** (2026-05-27, PR #440)

#### ~~DataMapperBase test補強 (#407 deferred chunk)~~ → **FT21 complete** (2026-05-27)

---

## Recently picked-up (archive trail)

When a candidate becomes a trial, move it to this section briefly so we can see the recent flow.

- **FT24 — CLI command framework** (2026-05-27): `Nene\Xion\Command` abstract base class; 4 CLI scripts refactored to thin shells; `initSQLite.php` fixed to use SchemaCompiler (removed hardcoded DDL); 16 unit tests. PR #457.
- **FT23 — NENE2 pattern survey** (2026-05-27): systematic review of NENE2 FT80–99; 1 code fix (`JSON_UNESCAPED_UNICODE`), 19 new docs, 1 enhanced doc. PR #455.
- **FT22 — ai-agent-journey** (2026-05-27): clean subagent built `bookmarks` REST service end-to-end using only docs. 5 doc gaps found (F-1/F-5 fixed immediately, F-2/F-3/F-4 deferred as Issues #446-#450). PR #451.
- **FT21 — DataMapperBase test補強** (2026-05-27): 20 unit tests for `execute()` / `executeQuery()` / `decorate()` / `assoc()` / `KEY_SID` / `getTableColumn()` / `getSearchARRAY()`. Mock PDO/PDOStatement; no real DB. PR #444.
- **ADR-0012 — PHP version policy** (2026-05-27): \`"php": ">=8.4"\` declared; upgrade cadence documented. PR #442.
- **static-analysis-baseline cleanup** (2026-05-27): all 6 Phan baseline entries resolved. PR #440. Baseline now empty.
- **ADR-0011 — Smarty selection** (2026-05-27): retrospective ADR. PR #438. Records why Smarty over Twig/Blade + revisit triggers.
- **FT20 — server-timing** (2026-05-27): `ServerTiming` + `NENE_SERVER_TIMING_ENABLED` env. PR #435 (feat) + #436 (docs). `Server-Timing: app;dur=X.X`; ADR-0007 future concern resolved.
- **FT19 — structured-logs** (2026-05-27): `LogFormatterFactory` + `NENE_LOG_FORMAT=json` env. PR #432 (feat) + #433 (docs). Monolog JsonFormatter; log aggregator ready.
- **FT18 — session-storage-backend** (2026-05-26): ADR-0010. `RedisSessionHandler` + `SessionHandlerFactory` + `predis/predis`. PR #429 (feat) + #430 (docs). Resolves commercial-feasibility concern #3.
- **FT17 — schema-diff CLI** (2026-05-23): ADR-0009 implementation. Closed all 4 PRs same day.
- **FT16 — agent-bearer-auth** (2026-05-22): cross-repo handoff from nene-mcp #380. ADR-0008.
- **FT15 — request-id** (2026-05-22): ADR-0007 generality validation. Resulted in `RequestId` + Monolog processor.
- **FT14 — security-headers** (2026-05-22): ADR-0007. Closed FT7 F-6 / FT8 F-4 long-standing decoration trap.
- **FT13 — email-sending** (2026-05-22): ADR-0006. `Mailer` + `MailMessage` + mailpit dev catcher.

Older trials live in their dated reports — this archive trail is recent-context only.
