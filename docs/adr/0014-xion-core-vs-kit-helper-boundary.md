# ADR-0014 — Split `Nene\Xion` into framework core (`Nene\Xion`) and helper catalogue (`Nene\Kit`)

**Status**: Accepted
**Date**: 2026-05-29

## Context

By `v0.3.0`, `class/xion/` (namespace `Nene\Xion`) holds **282 classes**.
`docs/project.md` defines `class/xion/` as *framework core*: "dispatching,
controller base behavior, request/session helpers, response helpers, database
base classes, logging, and transaction boundaries." In practice only ~75 of the
282 classes match that definition. The remaining ~200 — produced by the Phase 7
field-trial loop (FT1–FT287) — are **application-domain helper classes**
(`DailyStreak`, `AffiliateClick`, `GiftCard`, `Poll`, `UtmCampaign`, `Raffle`, …):
small, single-purpose, DB-backed building blocks for features, not part of the
framework runtime.

This dilutes the project's central promise — "a small framework one developer
can read end-to-end." Symptoms:

- The runtime core is buried among ~200 optional helpers.
- `class/xion/INDEX.md` had to invent 13 domain groupings to stay navigable — a
  signal the namespace is carrying more than one responsibility.
- A newcomer (human or AI) cannot tell, from the namespace alone, whether a
  class is *the framework* or an *optional helper*.

These helpers are individually high-quality; the problem is their **placement**
and **mixing** with framework core, not their existence.

## Decision

Split the single `Nene\Xion` namespace into two, **within the same repository**:

- **`Nene\Xion` (`class/xion/`) — framework core + platform services.**
  Everything required to boot the framework, dispatch a request, render, talk to
  the database, and host cross-cutting concerns. Stays small and readable.
- **`Nene\Kit` (`class/kit/`) — application helper catalogue.**
  The opt-in, reusable, DB-backed feature building blocks from the field-trial
  loop. Each is independently usable and carries its own DDL; none is required
  for the framework to run.

`composer.json` gains one PSR-4 line: `"Nene\\Kit\\": "class/kit/"`.

### Classification principle

> **The boot test:** *Could this class be deleted and the framework still boot
> and serve the sample app's core routes (`/`, `/health`, `/session/login`,
> `/todo`)?* If **yes** → `Nene\Kit`. If **no** → `Nene\Xion`.

Practical heuristic using the existing `INDEX.md` domains:

| INDEX domain | Destination | Notes |
|---|---|---|
| HTTP Layer | `Nene\Xion` | Dispatcher, ControllerBase, Request, HttpResponse/Emitter/Termination, RouteContext, JsonResponder, View, pagination primitives |
| Infrastructure & DB | mostly `Nene\Xion` | PdoConnection, DataMapperBase, DataModelBase, TransactionManager, DbUpsert, SchemaDefinition/Compiler/Differ, CLI Commands, EnvLoader, Initialize — **except** the key-value/config helpers (CacheEntry, ConfigStore, SystemSetting, TenantConfig, SequenceNumber, DataRetention, ChecksumRegistry) which are **Kit** |
| Auth & Sessions | split | Plumbing → `Nene\Xion` (AuthSession, CsrfProtectionPolicy, SessionHandlerFactory, RedisSessionHandler, BearerAuth). Auth *features* → `Nene\Kit` (TotpAuthenticator, OAuthToken, MagicLink, OtpChallenge, PinCode, Password\*, InviteCode, etc.) |
| Errors / observability / mail | `Nene\Xion` | ErrorCode, DomainException, ApiResponse, Log, LogFormatterFactory, RequestId, ResponseDecorator, HttpCache, Mailer, MailMessage |
| All other domains | `Nene\Kit` | Access Control, Content/CMS, Users/Profiles, Notifications, Files/Media, Commerce/Billing, Analytics/Audit (incl. AuditLog/AccessLog/EventLog/PageView/etc.), Social/Community, API/Integration, Tasks/Workflows |

Ambiguous cases are resolved in the migration PR review against the boot test;
this ADR sets the principle, not an exhaustive per-class list.

### Tooling and docs

- `composer make:xion` becomes the **core** scaffolder; add `composer make:kit`
  (the same skeleton, `Nene\Kit` namespace, `class/kit/`, `tests/Unit/Kit/`).
  New field-trial helpers are generated into `Nene\Kit` going forward.
- `composer xion:index` → split into a core list in `class/xion/INDEX.md` and the
  catalogue in `class/kit/INDEX.md` (add `composer kit:index`).
- Kit classes that depend on framework infra (`PdoConnection`, `DbUpsert`) gain
  explicit `use Nene\Xion\…;` imports (they currently rely on same-namespace
  resolution).

## Alternatives considered

| Option | Why not now |
|---|---|
| **B — Separate Composer package (`hideyukimori/nene-kit`)** | Cleanest conceptually, but premature: no external consumer yet, and a second repo/CI/release-coupling is heavy while the catalogue is still actively growing. Revisit around `v1.0.0` once the boundary has stabilised — this ADR is the prerequisite step toward it. |
| **C — Keep one namespace, document only** | PSR-4 cannot physically subfolder without a namespace change, so "C" reduces to the status quo (INDEX groupings). Does not restore the small-core promise or the human/AI judgability problem. |

## Consequences

- **Restores the "small core" promise**: `Nene\Xion` shrinks to ~75 readable
  classes; the helper catalogue is clearly opt-in under `Nene\Kit`.
- **Backwards-incompatible namespace change** for the moved helpers
  (`Nene\Xion\GiftCard` → `Nene\Kit\GiftCard`). Acceptable: the project is
  pre-1.0 and has no external consumers of the helper classes. The framework-core
  public surface (`Nene\Xion\ControllerBase`, etc.) is unchanged.
- **Large mechanical migration**: ~200 classes + their tests move and change
  namespace; cross-namespace `use` imports are added. To keep PRs reviewable,
  migrate **in INDEX-domain batches** (one PR per domain), each green on
  `composer precommit`, rather than one 400-file PR.
- Sets up a clean later extraction to a standalone package (Option B) if/when an
  external consumer appears.
- `SchemaDefinition` remains the sample-app schema source; Kit helpers continue
  to carry their own DDL in docblocks (unchanged by this ADR).
