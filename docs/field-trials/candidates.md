# Field Trial Candidates

Durable backlog of trial themes worth running, with notes on why each one matters and what's blocking it. Pair with `docs/field-trials/README.md` (methodology) and `docs/field-trials/follow-ups.md` (deferred findings).

This file is for **forward-looking** ideas — once a trial fires, its record moves to a dated `docs/field-trials/YYYY-MM-field-trial-N.md` report and the candidate row gets struck through or removed.

## How to use this file

- **Maintainers**: scan this list when choosing the next trial. Pick whatever fits the current bandwidth + curiosity.
- **AI agents**: when the user asks "next trial を提案して", use this list as the primary source rather than re-deriving candidates from memory. Add new candidates here whenever a session surfaces one.
- **Trim regularly**: stale candidates (≥6 months old without being picked up, or rendered moot by other work) are removed by whichever session notices them.

---

## Active candidates (FT68+)

### FT68 — Tag / Label System

**Why**: エンティティへのタグ付け（M:N 関係）。記事・商品・タスクなどに複数タグを付与・取得。
**Scope**: `Nene\Xion\TagManager` — attach/detach/syncTags/tagsFor/entitiesWithTag、重複防止。
**Size**: small.

### FT69 — Comment Thread

**Why**: 階層コメント（親コメントへの返信）。ブログ・レビュー・フォーラムで頻出。
**Scope**: `Nene\Xion\CommentThread` — post/reply/list/softDelete、parent_id 階層、ネスト深さ制限。
**Size**: small–medium.

### FT70 — Subscription / Plan

**Why**: SaaS のユーザープラン管理。ユーザーを plan に紐付け、有効期限・更新・キャンセルを管理。
**Scope**: `Nene\Xion\Subscription` — subscribe/cancel/isActive/currentPlan、プラン変更履歴。
**Size**: small–medium.

### FT71 — Search History

**Why**: ユーザーごとの検索履歴。最近の検索クエリを保持し、重複除去・上限管理。
**Scope**: `Nene\Xion\SearchHistory` — push/recent/clear、重複時は更新（upsert）、上限 N 件。
**Size**: small.

### FT72 — Bookmark / Saved Items

**Why**: ユーザーが任意エンティティをブックマーク。お気に入り・ウィッシュリスト・後で読む等に汎用。
**Scope**: `Nene\Xion\Bookmark` — save/remove/isSaved/list、entity_type + entity_id 汎用設計。
**Size**: small.

---

## 保留候補（trigger-based）

### FT36 — バックグラウンドジョブ ★大型・要 ADR

**NENE2 対応**: FT65 (job queue), FT72 (dead letter queue), FT116
**Why**: メール送信・ファイル処理・定期クリーンアップが今はリクエストをブロックする。ADR-class。
**Open design questions**: symfony/messenger vs DB queue vs lightweight cron。
**Trigger**: 実アプリで "POST /foo が8秒かかる" 摩擦が発生したとき。
**Size**: large / ADR.

### Observability — OpenTelemetry traceparent / tracestate
**Why**: 業界標準の分散トレーシング。現状は `X-Request-ID` のみ。
**Trigger**: 実デプロイで OTel コレクターが必要になったとき。Pre-implement しない。
**Size**: ADR + medium.

### Structural — Constraint-changes ADR (unique / FK additions)
**Why**: ADR-0009 が制約変更を "warning-only" パスに置いた。実運用で摩擦が出たら昇格。
**Trigger**: 3件以上の「UNIQUE 制約追加が辛かった」オペレーター事例。
**Size**: ADR + medium.

### Structural — Multi-tenancy
**Why**: `users` テーブルはシングルテナント。B2B SaaS デプロイで row-scoped 隔離が必要になる。
**Trigger**: 実デプロイが求めたとき。先設計はオーバーエンジニアリングリスクが高い。
**Size**: ADR + large.

### Meta — docs-journey-newcomer
**Why**: ai-agent-journey の人間版。NeNe を初めて触る開発者との実施。
**Trigger**: ボランティアが現れたとき。
**Size**: medium, ボランティアの時間に合わせた time-box。

---

## Recently picked-up (archive trail)

When a candidate becomes a trial, move it to this section briefly so we can see the recent flow.

- **FT67 — Point / Loyalty System** (2026-05-27): `Nene\Xion\PointLedger` — append-only ledger, earn/spend/balance/history, negative-balance prevention. PR #501.
- **FT66 — Coupon / Promo Code** (2026-05-27): `Nene\Xion\CouponCode` — usage limits, per-user redemption, atomic redeem, two-table design. PR #500.
- **FT65 — Personal Access Token** (2026-05-27): `Nene\Xion\PersonalAccessToken` — ability-based auth, last_used_at tracking, pat_ prefix. PR #499.
- **FT64 — User Preferences** (2026-05-27): `Nene\Xion\UserPreference` — key-value store, type casting (getInt/getBool), upsert. PR #498.
- **FT63 — User Follow System** (2026-05-27): `Nene\Xion\FollowRelation` — directed follow/unfollow, isMutual, self-follow prevention. PR #497.
- **FT62 — Invitation Token** (2026-05-27): `Nene\Xion\InvitationToken` — 256-bit token, pending→accepted/cancelled lifecycle, expiry-before-status check, owner-enforced cancel. PR #496.
- **FT61 — Leaderboard** (2026-05-27): `Nene\Xion\Leaderboard` — best-score retention, rank via COUNT, tied-score sharing, limit clamping. PR #495.
- **FT60 — Content Draft** (2026-05-27): `Nene\Xion\ContentDraft` — draft/publish/archive lifecycle, SQL transition guards, 404-not-403 for hidden content. PR #494.
- **FT59 — Voting System** (2026-05-27): `Nene\Xion\VotingBooth` — upvote/downvote with toggle semantics, score, per-user state. PR #493.
- **FT58 — Notification Inbox** (2026-05-27): `Nene\Xion\NotificationInbox` — read_at nullable pattern, idempotent mark-read, ORDER BY id DESC. PR #492.
- **FT57 — JWT Refresh Token** (2026-05-27): `Nene\Xion\RefreshToken` — SHA-256 hash storage, rotation, replay detection via revokeAll(). PR #491.
- **FT56 — API Key Management** (2026-05-27): `Nene\Xion\ApiKey` — nk_ prefix+SHA-256, admin⊃write⊃read scope hierarchy, create-first rotation. PR #490.
- **FT55 — Distributed Lock** (2026-05-27): `Nene\Xion\DistributedLock` — DB-backed TTL lock, stale reclaim, owner-enforced release/renew. PR #489.
- **FT54 — JSON Schema Validator** (2026-05-27): `Nene\Func\JsonSchemaValidator` — zero-dependency JSON Schema subset; type/nullable/required/properties/items/enum/min/max/pattern. PR #488.
- **FT53 — Personal data export** (2026-05-27): `Nene\Func\PersonalDataExport` — GDPR Art.20 portability; provider registration; JSON export. PR #487.
- **FT52 — Event dispatcher** (2026-05-27): `Nene\Func\EventDispatcher` — in-process pub-sub; listen/emit/removeListener. PR #486.
- **FT51 — i18n** (2026-05-27): `Nene\Func\I18n` — static message catalog; {name} placeholders; locale fallback. PR #485.
- **FT50 — Input validation rules** (2026-05-27): `Nene\Func\Validator` fluent validator — required/maxLength/minLength/email/url/integer/in/regex + VALIDATION-FAILED error code. PR #483.
- **FT49 — Money value object** (2026-05-27): `Nene\Func\Money` immutable integer-based monetary value — add/subtract/multiply/round/format (JPY/USD/EUR). PR #482.
- **FT48 — Offset pagination** (2026-05-27): `Nene\Xion\OffsetPage` + `Nene\Func\PaginationHelper` — page/total/hasPrev/hasNext envelope + window() UI helper. PR #480.
- **FT47 — Tree/Hierarchy helper** (2026-05-27): `Nene\Func\TreeHelper` — build/ancestors/descendants/depth/flatten for adjacency-list trees. PR #478.
- **FT46 — File upload** (2026-05-27): `Nene\Xion\FileUpload` — require/load/validateSize/validateMime/moveTo fluent helper; uses finfo for MIME detection. PR #479.
- **FT45 — CORS** (2026-05-27): `Nene\Xion\Cors` — sendHeaders/handlePreflight/isAllowed; wildcard vs explicit origin; credentials support. PR #477.
- **FT44 — HTTP cache headers** (2026-05-27): `Nene\Xion\HttpCache` — sendCacheControl/sendLastModified/isNotModified/send304/sendNoCache; conditional GET (304). PR #476.
- **FT43 — Circuit breaker** (2026-05-27): `Nene\Xion\CircuitBreaker` — CLOSED/OPEN/HALF-OPEN state machine; DB-backed; configurable threshold + cooldown. PR #475.
- **FT42 — Signed URL** (2026-05-27): `Nene\Xion\SignedUrl` — HMAC-SHA256 sign/verify/requireValid with expiry; SIGNED-URL-EXPIRED (410) / SIGNED-URL-INVALID (403). PR #474.
- **FT41 — Account lockout** (2026-05-27): `Nene\Xion\LoginAttemptTracker` — DB-backed failure counter; locks at threshold; reset on success; ACCOUNT-LOCKED (423). PR #473.
- **FT40 — Batch operations** (2026-05-27): `Nene\Xion\BatchResult` — addSuccess/addFailure/httpStatus/toArray; 200/207/422 based on partial success. PR #472.
- **FT39 — API versioning + deprecation headers** (2026-05-27): `Nene\Xion\ApiDeprecation` — RFC 8594 Deprecation/Sunset/Link headers; ADR-0013 URI prefix versioning. PR #471.
- **FT38 — Full-text search helper** (2026-05-27): `Nene\Func\SearchQuery` — escapeLike/likePattern/sanitizeFts/normalize; FTS5 patterns doc. PR #470.
- **FT37 — Idempotency keys** (2026-05-27): `Nene\Xion\IdempotencyStore` — DB-backed get/put/hash; INSERT IGNORE/INSERT OR IGNORE; X-Idempotency-Key / X-Idempotent-Replayed. PR #469.
- **FT35 — Feature flags** (2026-05-27): `Nene\Func\FeatureFlagService` — DB-backed; 3-tier priority (user override → global → rollout%); deterministic crc32 bucket. PR #468.
- **FT34 — Webhook signing** (2026-05-27): `Nene\Xion\WebhookSigner` — Stripe-style `t=<ts>,v1=<hmac>`; hash_equals timing-safe; generateSecret(). PR #467.
- **FT33 — 監査ログ** (2026-05-27): `Nene\Xion\AuditLogger` — append-only audit_log; PDO injection; PDOException caught internally. PR #466.
- **FT32 — パスワードリセット** (2026-05-27): `Nene\Xion\PasswordResetToken` — bin2hex(random_bytes(32)) + SHA-256 stored hash; isExpired/expiresAt. PR #465.
- **FT31 — RBAC** (2026-05-27): `Nene\Xion\RoleGuard` — JWT claims-based require/requireAny/has; 401 vs 403 distinction. PR #464.
- **FT30 — JWT HS256** (2026-05-27): `Nene\Xion\JwtCodec` — pure PHP HMAC-SHA256; issue/decode/require; alg:none防御; JWT-INVALID (401). PR #463.
- **FT29 — State machine** (2026-05-27): `Nene\Func\WorkflowDefinition` — code-driven transition map; assertTransition 409; initial/allowed/allStates. PR #462.
- **FT28 — Rate limiting** (2026-05-27): `Nene\Func\RateLimiter` + Redis storage; fixed-window INCR+EXPIRE; X-RateLimit-* headers; 429 + Retry-After. PR #461.
- **FT27 — Optimistic locking / ETag** (2026-05-27): `Nene\Xion\OptimisticLock` — parseIfMatch/etagFor/sendETag/requireVersion/conflict; 412/428. PR #460.
- **FT26 — Soft delete** (2026-05-27): `DataMapperBase::SOFT_DELETE` constant; softDelete/restore/findTrashed/purge; deleted_at フィルタ自動適用。PR #459.
- **FT25 — Cursor-based pagination** (2026-05-27): `Nene\Xion\Cursor` + `CursorPage`; base64url token; (created_at, id) keyset; LIMIT n+1 probe. PR #458.
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
