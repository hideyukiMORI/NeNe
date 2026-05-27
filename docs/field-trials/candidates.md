# Field Trial Candidates

Durable backlog of trial themes worth running, with notes on why each one matters and what's blocking it. Pair with `docs/field-trials/README.md` (methodology) and `docs/field-trials/follow-ups.md` (deferred findings).

This file is for **forward-looking** ideas — once a trial fires, its record moves to a dated `docs/field-trials/YYYY-MM-field-trial-N.md` report and the candidate row gets struck through or removed.

## How to use this file

- **Maintainers**: scan this list when choosing the next trial. Pick whatever fits the current bandwidth + curiosity.
- **AI agents**: when the user asks "next trial を提案して", use this list as the primary source rather than re-deriving candidates from memory. Add new candidates here whenever a session surfaces one.
- **Trim regularly**: stale candidates (≥6 months old without being picked up, or rendered moot by other work) are removed by whichever session notices them.

---

## NENE2 parity roadmap (FT25–FT36+)

**Goal**: NeNe が NENE2 と同等の品質を担保する。構造は異なるが、アプリ実装者が必要とするパターンを一通り実装・ドキュメント化する。

NENE2 は FT2–FT155（156本）完了済み。NeNe は FT1–FT24 完了。FT23 で NENE2 FT80–99 のパターンを docs として移植済みだが、コード実装はこれから。

以下の優先順位で進める。

### FT25 — Cursor-based pagination

**NENE2 対応**: FT42 (cursorlog), FT63 (cursorlog), FT100 (offset vs cursor performance)
**Why**: 実アプリほぼ全てに必要。OFFSET は大規模データで破綻するため cursor 方式を先に整備する。
**Scope**: `DataMapperBase` or helper class に `paginateCursor()` 相当を追加、OpenAPI `cursor`/`next_cursor` 規約、howto doc。
**Size**: small–medium.

### FT26 — Soft delete

**NENE2 対応**: FT35 (softlog), FT49 (softdelete), FT62 (softdeletelog), FT108
**Why**: 削除取り消し・ゴミ箱・論理削除は多くのサービスで必須。`deleted_at` カラム + Mapper フィルタのパターン確立。
**Scope**: `deleted_at` 規約、`DataMapperBase` への論理削除フィルタ、restore、howto doc。
**Size**: small.

### FT27 — Optimistic locking / ETag 実装

**NENE2 対応**: FT39 (locklog), FT54 (optimisticlog), FT56 (etaglog), FT70, FT105, FT106
**Why**: `docs/development/optimistic-locking.md` はあるが実装がない。`version` カラム + `If-Match` ヘッダの実働コードを整備する。
**Scope**: `ControllerBase` または helper に ETag/If-Match 処理、Mapper に version bump、テスト。
**Size**: medium.

### FT28 — Rate limiting 実装

**NENE2 対応**: FT46 (ratelimitlog), FT53 (ratelog), FT73 (quotalog), FT107
**Why**: `docs/development/rate-limiting.md` はあるが実装がない。Redis INCR+EXPIRE パターンを NeNe の Middleware/ControllerBase フックとして実装する。
**Scope**: `RateLimiter` クラス、`preAction()` フック例、429 レスポンス、Retry-After ヘッダ、テスト。
**Size**: medium.

### FT29 — State machine 実装

**NENE2 対応**: FT40 (flowlog), FT61 (statemachinelog), FT68
**Why**: `docs/development/state-machines.md` はあるが実装がない。注文ワークフローや承認フローの canonical パターンを確立する。
**Scope**: `WorkflowDefinition` / transitions 規約、遷移違反で 409、howto doc 更新。
**Size**: medium.

### FT30 — JWT 認証

**NENE2 対応**: FT110, FT113 (JWT refresh token rotation), FT136 (tokenlog)
**Why**: Bearer auth（FT16）は NeNe 独自トークン。JWT (HS256/RS256) で外部サービスや SPA との統合に対応する。
**Scope**: `JwtAuthenticator`、`exp`/`sub` 検証、`alg:none` 防御、refresh rotation、ADR。
**Size**: medium.

### FT31 — RBAC

**NENE2 対応**: FT111
**Why**: 現状は「認証済みか否か」だけ。ロールベースのアクセス制御を `preAction()` フックで実装する最小形を確立する。
**Scope**: `roles` テーブル、`ControllerBase` に `requireRole()` フック、テスト。
**Size**: medium.

### FT32 — パスワードリセット

**NENE2 対応**: FT126
**Why**: ユーザー認証を持つサービスに必須。`invitation-tokens.md` の派生として実装できる。
**Scope**: リセットトークン生成・メール送信・検証・パスワード更新フロー、expiry、howto doc。
**Size**: small.

### FT33 — 監査ログ

**NENE2 対応**: FT59 (auditlog), FT74 (auditlog), FT114
**Why**: コンプライアンスや障害調査に必要。append-only の `audit_logs` テーブルへの書き込みパターン。
**Scope**: `AuditLogger` helper、Mapper の mutation フック例、検索 API、howto doc。
**Size**: small.

### FT34 — Webhook 配信 + HMAC 署名

**NENE2 対応**: FT48 (webhooklog), FT104 (hmaclog), FT120
**Why**: 外部サービスとの統合に必須。HMAC-SHA256 署名付き配信、リトライ、タイムアウト。
**Scope**: `WebhookDispatcher`、HMAC 署名、配信ログ、リトライ戦略、howto doc。
**Size**: medium.

### FT35 — Feature flags 実装

**NENE2 対応**: FT71, FT121
**Why**: `docs/development/feature-flags.md` はあるが実装がない。Redis / DB バックエンドでのグローバル + per-user override。
**Scope**: `FeatureFlag` helper、Redis / DB 両対応、howto doc 更新。
**Size**: small.

### FT36 — バックグラウンドジョブ

**NENE2 対応**: FT65 (job queue), FT72 (dead letter queue), FT116
**Why**: メール送信・ファイル処理・定期クリーンアップが今はリクエストをブロックする。ADR-class。
**Open design questions**: symfony/messenger vs DB queue vs lightweight cron。
**Trigger**: 実アプリで "POST /foo が8秒かかる" 摩擦が発生したとき。
**Size**: large / ADR.

---

## 他の保留候補

### Observability

#### OpenTelemetry traceparent / tracestate
**Why**: 業界標準の分散トレーシング。現状は `X-Request-ID` のみ。
**Trigger**: 実デプロイで OTel コレクターが必要になったとき。Pre-implement しない。
**Size**: ADR + medium.

### Structural / governance

#### Constraint-changes ADR (unique / FK additions)
**Why**: ADR-0009 が制約変更を "warning-only" パスに置いた。実運用で摩擦が出たら昇格。
**Trigger**: 3件以上の「UNIQUE 制約追加が辛かった」オペレーター事例。
**Size**: ADR + medium.

#### Multi-tenancy
**Why**: `users` テーブルはシングルテナント。B2B SaaS デプロイで row-scoped 隔離が必要になる。
**Trigger**: 実デプロイが求めたとき。先設計はオーバーエンジニアリングリスクが高い。
**Size**: ADR + large.

### Meta / evaluation

#### docs-journey-newcomer
**Why**: ai-agent-journey の人間版。NeNe を初めて触る開発者との実施。
**Trigger**: ボランティアが現れたとき。
**Size**: medium, ボランティアの時間に合わせた time-box。

---

## Recently picked-up (archive trail)

When a candidate becomes a trial, move it to this section briefly so we can see the recent flow.

- **FT24 — CLI command framework** (2026-05-27): `Nene\Xion\Command` abstract base class; 4 CLI scripts refactored to thin shells; `initSQLite.php` fixed to use SchemaCompiler (removed hardcoded DDL); 16 unit tests. PR #457.
- **FT23 — NENE2 pattern survey** (2026-05-27): systematic review of NENE2 FT80–99; 1 code fix (`JSON_UNESCAPED_UNICODE`), 19 new docs, 1 enhanced doc. PR #455.
- **FT22 — ai-agent-journey** (2026-05-27): clean subagent built `bookmarks` REST service end-to-end using only docs. 5 doc gaps found (F-1/F-5 fixed immediately, F-2/F-3/F-4 deferred as Issues #446-#450). PR #451.
- **FT21 — DataMapperBase test補強** (2026-05-27): 20 unit tests for `execute()` / `executeQuery()` / `decorate()` / `assoc()` / `KEY_SID` / `getTableColumn()` / `getSearchARRAY()`. Mock PDO/PDOStatement; no real DB. PR #444.
- **ADR-0012 — PHP version policy** (2026-05-27): `"php": ">=8.4"` declared; upgrade cadence documented. PR #442.
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
