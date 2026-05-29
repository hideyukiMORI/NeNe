# Field Trial Candidates

Durable backlog of trial themes worth running, with notes on why each one matters and what's blocking it. Pair with `docs/field-trials/README.md` (methodology) and `docs/field-trials/follow-ups.md` (deferred findings).

This file is for **forward-looking** ideas — once a trial fires, its record moves to a dated `docs/field-trials/YYYY-MM-field-trial-N.md` report and the candidate row gets struck through or removed.

## How to use this file

- **Maintainers**: scan this list when choosing the next trial. Pick whatever fits the current bandwidth + curiosity.
- **AI agents**: when the user asks "next trial を提案して", use this list as the primary source rather than re-deriving candidates from memory. Add new candidates here whenever a session surfaces one.
- **Trim regularly**: stale candidates (≥6 months old without being picked up, or rendered moot by other work) are removed by whichever session notices them.

---

## Active candidates

The Xion helper wave (FT78–FT264) is ongoing as of 2026-05-28. Trigger-based and structural candidates are listed in the next section.

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

- **FT286 — PinnedItem** (2026-05-29): `Nene\Xion\PinnedItem` — ordered pinned items per context; append-on-pin keeps position; moveToTop/moveToBottom reorder. PR #742.
- **FT285 — Endorsement** (2026-05-29): `Nene\Xion\Endorsement` — peer skill endorsements; one per subject/skill/endorser; self-endorse rejected; topSkills by count. PR #741.
- **FT284 — FunnelStep** (2026-05-29): `Nene\Xion\FunnelStep` — conversion-funnel step tracking; counts (distinct per step); conversionRate |F∩T|/|F|; drop-off analysis. PR #740.
- **FT283 — UtmCampaign** (2026-05-29): `Nene\Xion\UtmCampaign` — UTM marketing attribution touch capture; first/last touch; countBy (whitelisted field); campaign analytics. PR #739.
- **FT282 — AffiliateClick** (2026-05-29): `Nene\Xion\AffiliateClick` — affiliate click & conversion attribution; integer-cent revenue; convert() first-only; stats/conversionRate. PR #738.
- **FT281 — FeatureTour** (2026-05-29): `Nene\Xion\FeatureTour` — per-user one-time UI tour/coachmark state; shouldShow/markSeen/complete/dismiss; no status regression; resetAll. PR #737.
- **FT280 — IpReputation** (2026-05-29): `Nene\Xion\IpReputation` — running per-IP reputation score (atomic adjust); penalize/reward/isBad/worst/purgeBelow; feeds blocklist decisions. PR #736.
- **FT279 — RedactionRule** (2026-05-29): `Nene\Xion\RedactionRule` — configurable PII/secret regex masking rules; priority order; validated patterns; preg_replace null-guarded. PR #735.
- **FT278 — TermGlossary** (2026-05-29): `Nene\Xion\TermGlossary` — DB-backed term/definition glossary with categories; slug-keyed; LIKE-escaped search. PR #734.
- **FT277 — WeightedPicker** (2026-05-29): `Nene\Xion\WeightedPicker` — weighted random selection from a named pool; deterministic roll for reproducibility; zero-weight retained but never picked. PR #733.
- **FT276 — RoundRobinAssigner** (2026-05-29): `Nene\Xion\RoundRobinAssigner` — fair rotating pool assignment; persistent cursor; atomic next(); add/removeMember with cursor clamp. PR #731.
- **FT275 — RetrySchedule** (2026-05-29): `Nene\Xion\RetrySchedule` — exponential-backoff retry tracking; arm/backoff/due; exhaustion terminal; hands off to DeadLetterQueue. PR #730.
- **FT274 — DeadLetterQueue** (2026-05-29): `Nene\Xion\DeadLetterQueue` — parking lot for exhausted-retry messages; record/forQueue/requeue (atomic claim)/purgeOlderThan. PR #729.
- **FT273 — Heartbeat** (2026-05-29): `Nene\Xion\Heartbeat` — liveness/dead-man-switch per service; beat()/isAlive()/alive()/stale() over a freshness window. PR #728.
- **FT272 — PercentageRollout** (2026-05-29): `Nene\Xion\PercentageRollout` — sticky percentage feature rollout; deterministic crc32 bucketing, monotonic membership; isEnabled/setPercentage. PR #727.
- **FT271 — PasswordPolicy** (2026-05-29): `Nene\Xion\PasswordPolicy` — per-scope password complexity rules; validate() returns all violations; safe default when unconfigured. PR #726.
- **FT270 — EmailSuppression** (2026-05-29): `Nene\Xion\EmailSuppression` — do-not-send list (bounce/complaint/unsubscribe/manual); filter() keeps deliverable addresses; case-insensitive. PR #725.
- **FT269 — MaintenanceWindow** (2026-05-29): `Nene\Xion\MaintenanceWindow` — scheduled per-scope maintenance windows; isActive/activeWindow/upcoming over half-open intervals; purgeEnded. PR #724.
- **FT268 — DataRetention** (2026-05-29): `Nene\Xion\DataRetention` — central table->TTL retention policy registry and purge driver; setPolicy/due/purge with strict identifier validation. PR #723.
- **FT267 — ExchangeRate** (2026-05-29): `Nene\Xion\ExchangeRate` — effective-dated currency conversion rate table; integer-cent convertCents() with half-up rounding; rateAt/latest/history; no floats. PR #722.
- **FT266 — BusinessCalendar** (2026-05-29): `Nene\Xion\BusinessCalendar` — working-day calendar with weekend+holiday awareness; isBusinessDay/addBusinessDays/businessDaysBetween over half-open ranges; per-calKey holidays; SLA & due-date arithmetic. PR #720.
- **FT265 — SequenceNumber** (2026-05-29): `Nene\Xion\SequenceNumber` — gapless per-scope sequential numbering (invoice/order/ticket numbers); atomic next(); formatted() prefix+padding; peek()/reset(); cross-driver via DbUpsert. PR #717.
- **FT264 — PasswordExpiry** (2026-05-28): `Nene\Xion\PasswordExpiry` — password expiry policy; set() resets clock; forceChange() for incidents; expiringSoon() notification cron. PR #708.
- **FT263 — AccessLog** (2026-05-28): `Nene\Xion\AccessLog` — append-only resource access log; forResource/byActor/byAction; purgeOlderThan(). PR #707.
- **FT262 — TextTemplate** (2026-05-28): `Nene\Xion\TextTemplate` — body-only text template for SMS/push/Slack; {{variable}} substitution; locale fallback; cross-driver upsert. PR #706.
- **FT261 — ImpersonationLog** (2026-05-28): `Nene\Xion\ImpersonationLog` — admin impersonation session audit; start()/end(); active(?admin); purgeOlderThan() skips active. PR #705.
- **FT260 — RecurringPayment** (2026-05-28): `Nene\Xion\RecurringPayment` — subscription billing schedule; due() cron; recordBilling() advances next_billing_date; pause/resume/cancel. PR #704.
- **FT259 — ApiWebhook** (2026-05-28): `Nene\Xion\ApiWebhook` — webhook subscription endpoint registry; auto-generates HMAC secret; rotateSecret(); forEvent() active subscribers. PR #703.
- **FT258 — ContentReport** (2026-05-28): `Nene\Xion\ContentReport` — user-submitted content moderation reports; STATUS_PENDING/ACTIONED/DISMISSED; action()/dismiss() guard pending status. PR #702.
- **FT257 — ProfileBadge** (2026-05-28): `Nene\Xion\ProfileBadge` — gamification badge award/revoke; award() idempotent; userBadges()/usersWithBadge()/countForBadge(). PR #701.
- **FT256 — UserFeedback** (2026-05-28): `Nene\Xion\UserFeedback` — general NPS/star satisfaction feedback; averageScore(); countByScore(); forUser()/recent()/clearContext(). PR #700.
- **FT255 — DailyStreak** (2026-05-28): `Nene\Xion\DailyStreak` — per-user daily activity streak; checkin() idempotent same-day; longestStreak preserved through reset(). PR #699.
- **FT250 — KpiTracker** (2026-05-28): `Nene\Xion\KpiTracker` — KPI/OKR metric tracking with target/actual; record() time-series; progress() {actual, target, pct}; purgeOlderThan() per definition. PR #693.
- **FT249 — FeatureRequest** (2026-05-28): `Nene\Xion\FeatureRequest` — product feature requests with voting; OPEN→PLANNED→IN_PROGRESS→SHIPPED; one-vote-per-user; top() by vote count. PR #692.
- **FT248 — DocumentSignature** (2026-05-28): `Nene\Xion\DocumentSignature` — e-signature workflow; multi-signatory; sign() auto-completes; decline() cancels; pendingFor() inbox. PR #691.
- **FT247 — EventRsvp** (2026-05-28): `Nene\Xion\EventRsvp` — event RSVP; RESPONSE_YES/NO/MAYBE; capacity guard; guests count; checkin() marks attendance. PR #690.
- **FT246 — AssetRegistry** (2026-05-28): `Nene\Xion\AssetRegistry` — asset inventory; AVAILABLE/ASSIGNED/RETIRED; assign() records history; unassign() sets returned_at. PR #689.
- **FT245 — BudgetTracker** (2026-05-28): `Nene\Xion\BudgetTracker` — period budget allocation; spend() with OverflowException; auto-marks EXHAUSTED at 100%; remaining()/isExhausted(). PR #688.
- **FT244 — ChangeRequest** (2026-05-28): `Nene\Xion\ChangeRequest` — RFC change-management; DRAFT→SUBMITTED→APPROVED/REJECTED→IMPLEMENTED→CLOSED; each transition guards prior status. PR #687.
- **FT243 — IncidentLog** (2026-05-28): `Nene\Xion\IncidentLog` — IT incident tracking; SEVERITY_P1–P4; two-table with events; investigate()/resolve()/close(); bySeverity(). PR #686.
- **FT242 — GiftCard** (2026-05-28): `Nene\Xion\GiftCard` — partial-redemption gift cards; auto-generates code; STATUS_EXHAUSTED at balance=0; expireStale(). PR #685.
- **FT241 — PresenceTracker** (2026-05-28): `Nene\Xion\PresenceTracker` — online presence per user+context; cross-driver upsert; isOnline() window-based; online()/onlineIn(). PR #684.
- **FT240 — SubscriptionPlan** (2026-05-28): `Nene\Xion\SubscriptionPlan` — subscription lifecycle; STATUS_TRIAL/ACTIVE/CANCELLED/EXPIRED; isActive() checks status+date range; expireStale(). PR #683.
- **FT239 — VotePoll** (2026-05-28): `Nene\Xion\VotePoll` — two-table polls; one-vote-per-user upsert; vote() validates option vs. JSON list; results() by popularity. PR #682.
- **FT238 — MediaConversionJob** (2026-05-28): `Nene\Xion\MediaConversionJob` — async media processing FIFO queue; nextPending()/start()/complete()/fail()/retry(); attempts counter. PR #680.
- **FT237 — SlaTracker** (2026-05-28): `Nene\Xion\SlaTracker` — SLA/SLO breach detection; start() computes deadline; pause/resume accumulates paused_seconds; breached() cron query. PR #679.
- **FT236 — DeviceFingerprint** (2026-05-28): `Nene\Xion\DeviceFingerprint` — device recognition; SHA-256 hash; seen() cross-driver upsert increments seen_count; isTrusted()/isBlocked(). PR #678.
- **FT235 — CreditNote** (2026-05-28): `Nene\Xion\CreditNote` — financial credit notes; integer-cent amounts; PENDING→APPLIED/VOIDED; pendingTotal() sum. PR #677.
- **FT234 — ScoreBoard** (2026-05-28): `Nene\Xion\ScoreBoard` — high-score table; top() derives MAX(score) per player; rank() counts players with higher best; period scoping. PR #676.
- **FT233 — ResourceReservation** (2026-05-28): `Nene\Xion\ResourceReservation` — time-bounded reservation with overlap detection; isAvailable() excludeId; forResource() range query. PR #675.
- **FT232 — StockAlert** (2026-05-28): `Nene\Xion\StockAlert` — stock availability alerts; pendingTriggers()/markTriggered() for cron; dismiss() silences without delete. PR #674.
- **FT231 — EntitySnapshot** (2026-05-28): `Nene\Xion\EntitySnapshot` — point-in-time entity snapshots; findAt() nearest; list() metadata only; purgeOlderThan() TTL. PR #673.
- **FT230 — RecipientGroup** (2026-05-28): `Nene\Xion\RecipientGroup` — mailing list groups; UNIQUE slug; addMember() cross-driver upsert; isMember()/groupsFor(). PR #672.
- **FT229 — SurveyTemplate** (2026-05-28): `Nene\Xion\SurveyTemplate` — two-table form template with typed questions; inactive by default; activate()/deactivate(). PR #671.
- **FT210 — ResourceLock** (2026-05-27): `Nene\Xion\ResourceLock` — advisory entity locking with TTL; acquire() int|null; extend(); releaseExpired() cron cleanup. PR #650.
- **FT209 — ContentTag** (2026-05-27): `Nene\Xion\ContentTag` — flexible entity tagging; tags normalised to lowercase slugs; cloud() tag→count; entitiesWith() reverse lookup. PR #649.
- **FT208 — DeviceToken** (2026-05-27): `Nene\Xion\DeviceToken` — push notification device token management per user; register() reactivates existing; deleteInactive() cleanup. PR #648.
- **FT207 — TaxRate** (2026-05-27): `Nene\Xion\TaxRate` — regional tax rate lookup by region+category; calculateCents()/totalWithTaxCents() integer-cent safety. PR #647.
- **FT206 — WaitlistEntry** (2026-05-27): `Nene\Xion\WaitlistEntry` — product/feature waitlist with queue positioning; inviteNext() batch; position() 1-based rank. PR #646.
- **FT205 — GdprRequest** (2026-05-27): `Nene\Xion\GdprRequest` — GDPR data-subject request tracking (access/rectification/erasure/portability); pending→acknowledged→completed|rejected. PR #645.
- **FT204 — IntegrationLog** (2026-05-27): `Nene\Xion\IntegrationLog` — external API call log with service/endpoint/status/body/duration; errors() non-2xx; deleteOlderThan() TTL. PR #644.
- **FT203 — TimeSlot** (2026-05-27): `Nene\Xion\TimeSlot` — appointment/time-slot booking with capacity; atomic UPDATE WHERE booked < capacity. PR #643.
- **FT202 — TimeEntry** (2026-05-27): `Nene\Xion\TimeEntry` — work time tracking with start/stop timers and manual entries; totalSeconds(). PR #642.
- **FT201 — InventoryStock** (2026-05-27): `Nene\Xion\InventoryStock` — product/SKU stock tracking with atomic three-phase reserve/release/commit; inventory_log. PR #641.
- **FT200 — UserSegment** (2026-05-27): `Nene\Xion\UserSegment` — user cohort/segment assignment; two-table design (definitions + members); idempotent addUser; segmentsFor/usersIn. PR #639.
- **FT199 — AppVersion** (2026-05-27): `Nene\Xion\AppVersion` — deployment/release version history per environment; current() latest; history() newest-first; environments(). PR #638.
- **FT198 — OrderLine** (2026-05-27): `Nene\Xion\OrderLine` — e-commerce order with line items (two-table); integer-cent amounts; pending→confirmed→shipped→delivered|cancelled. PR #637.
- **FT197 — TopicSubscription** (2026-05-27): `Nene\Xion\TopicSubscription` — user subscriptions to named topics; idempotent subscribe; subscribersOf() fanout; distinct from NewsletterSubscription. PR #636.
- **FT196 — DataImportJob** (2026-05-27): `Nene\Xion\DataImportJob` — two-table CSV import job tracking with per-row errors; pending→validating→processing→done|failed; error_count. PR #635.
- **FT195 — UserTier** (2026-05-27): `Nene\Xion\UserTier` — gamification tier assignment with two-table design (current + history); hasEverHad(). PR #633.
- **FT194 — FaqItem** (2026-05-27): `Nene\Xion\FaqItem` — FAQ articles with category, position ordering, keyword search, helpfulness voting. PR #632.
- **FT193 — ProductReview** (2026-05-27): `Nene\Xion\ProductReview` — entity reviews with 1–5 star ratings; approval workflow; helpfulness voting; one review per user per entity. PR #631.
- **FT192 — MediaGallery** (2026-05-27): `Nene\Xion\MediaGallery` — ordered media item gallery per entity; cover photo designation; captions; position reordering. PR #630.
- **FT191 — SystemSetting** (2026-05-27): `Nene\Xion\SystemSetting` — typed global app settings (string/int/bool/json) with categories; cross-driver upsert; distinct from ConfigStore. PR #629.
- **FT190 — TwoFactorBackupCode** (2026-05-27): `Nene\Xion\TwoFactorBackupCode` — one-time 2FA recovery codes; SHA-256 hash storage; generate() invalidates all previous codes. PR #628.
- **FT189 — AccessControl** (2026-05-27): `Nene\Xion\AccessControl` — per-resource subject ACL; idempotent grant; complements global RBAC (RoleGuard). PR #627.
- **FT188 — DailyDigest** (2026-05-27): `Nene\Xion\DailyDigest` — per-user digest item accumulator; allPending() grouped by user; batch markSent; purgeSent. PR #626.
- **FT187 — EventLog** (2026-05-27): `Nene\Xion\EventLog` — append-only domain event log (event sourcing–lite); forAggregate/ofType/byActor queries; JSON payload. PR #625.
- **FT186 — SupportTicket** (2026-05-27): `Nene\Xion\SupportTicket` — help-desk ticketing with support_tickets + ticket_replies; status/priority constants; addReply; countByStatus. PR #624.
- **FT185 — KnowledgeBase** (2026-05-27): `Nene\Xion\KnowledgeBase` — help articles with draft→published→archived lifecycle, category filter, keyword search, view tracking. PR #622.
- **FT184 — Reminder** (2026-05-27): `Nene\Xion\Reminder` — user-set future reminders; due() for cron-poll; markSent; purgeSent. PR #621.
- **FT183 — ContentFilter** (2026-05-27): `Nene\Xion\ContentFilter` — DB-backed banned word list with detect/mask and optional whole-word mode; in-memory cache. PR #620.
- **FT182 — SavedSearch** (2026-05-27): `Nene\Xion\SavedSearch` — per-user named search queries with upsert, usage tracking, and scope filtering. PR #619.
- **FT181 — Attachment** (2026-05-27): `Nene\Xion\Attachment` — file attachment metadata linked to any entity; totalBytes; forUser. PR #618.
- **FT180 — Watchlist** (2026-05-27): `Nene\Xion\Watchlist` — entity watch subscriptions; toggle; watcherIds; watching(userId). PR #617.
- **FT179 — Mention** (2026-05-27): `Nene\Xion\Mention` — @-mention tracking with unread inbox, markRead, unreadCount, mentionedIn. PR #616.
- **FT178 — Reaction** (2026-05-27): `Nene\Xion\Reaction` — emoji/symbol reactions per user; toggle; counts() grouped by type. PR #615.
- **FT177 — Checklist** (2026-05-27): `Nene\Xion\Checklist` — ordered checklist items on any entity; check/uncheck; percent(). PR #614.
- **FT176 — Label** (2026-05-27): `Nene\Xion\Label` — two-table colored label system; idempotent attach/detach; forEntity/entitiesWithLabel. PR #613.
- **FT175 — UserNote** (2026-05-27): `Nene\Xion\UserNote` — admin/staff notes attached to user records with pinning. PR #611.
- **FT174 — ScheduledTask** (2026-05-27): `Nene\Xion\ScheduledTask` — cron-style task schedule registry with last-run tracking and due() query. PR #610.
- **FT173 — GuestSession** (2026-05-27): `Nene\Xion\GuestSession` — anonymous visitor session with key-value data bag; login promotion via linkUser(). PR #609.
- **FT172 — PinCode** (2026-05-27): `Nene\Xion\PinCode` — short PIN/OTP issuance with attempt limiting and expiry; SHA-256 hash storage. PR #608.
- **FT171 — PaymentRecord** (2026-05-27): `Nene\Xion\PaymentRecord` — simple payment/transaction ledger; integer-cent amounts; pending→paid|failed, paid→refunded. PR #607.
- **FT170 — TrustScore** (2026-05-27): `Nene\Xion\TrustScore` — append-only fraud/trust score per user; signed deltas; below() for risk queries. PR #606.
- **FT169 — TeamMembership** (2026-05-27): `Nene\Xion\TeamMembership` — team/org membership with per-member roles; idempotent add; cross-driver upsert. PR #605.
- **FT168 — Approval** (2026-05-27): `Nene\Xion\Approval` — single-approver request/approve/reject workflow; cannot re-decide. PR #604.
- **FT167 — ShareLink** (2026-05-27): `Nene\Xion\ShareLink` — shareable links with optional password, view limits, and TTL expiry. PR #603.
- **FT166 — Quota** (2026-05-27): `Nene\Xion\Quota` — plan-based resource quota management; unlimited (0), TTL windows, auto-reset. PR #602.
- **FT165 — FileChunk** (2026-05-27): `Nene\Xion\FileChunk` — chunked file upload tracking and reassembly coordination. PR #600.
- **FT164 — CounterMetric** (2026-05-27): `Nene\Xion\CounterMetric` — named metric counters with daily/hourly bucket aggregation. PR #599.
- **FT163 — PushSubscription** (2026-05-27): `Nene\Xion\PushSubscription` — Web Push notification subscription registry. PR #598.
- **FT162 — ChangeLog** (2026-05-27): `Nene\Xion\ChangeLog` — human-readable entity change history with before/after snapshots. PR #597.
- **FT161 — ServiceAccount** (2026-05-27): `Nene\Xion\ServiceAccount` — machine-to-machine auth credentials with key rotation. PR #596.
- **FT160 — BatchJob** (2026-05-27): `Nene\Xion\BatchJob` — batch processing job tracking with progress lifecycle. PR #595.
- **FT159 — EmailTemplate** (2026-05-27): `Nene\Xion\EmailTemplate` — DB-stored email templates with {{variable}} substitution and locale support. PR #594.
- **FT158 — UserActivity** (2026-05-27): `Nene\Xion\UserActivity` — user last-seen tracking and named action event log. PR #593.
- **FT157 — OAuthToken** (2026-05-27): `Nene\Xion\OAuthToken` — OAuth2 access/refresh token storage with rotation; SHA-256 hashed. PR #592.
- **FT156 — ApiUsageLog** (2026-05-27): `Nene\Xion\ApiUsageLog` — per-API-key request tracking with endpoint and status logging. PR #591.
- **FT155 — DocumentLock** (2026-05-27): `Nene\Xion\DocumentLock` — collaborative editing lock with TTL; only holder can refresh/release. PR #590.
- **FT154 — EmailQueue** (2026-05-27): `Nene\Xion\EmailQueue` — DB-backed outgoing email queue with exponential backoff retry. PR #589.
- **FT153 — SlugRegistry** (2026-05-27): `Nene\Xion\SlugRegistry` — namespace-scoped URL slug generation and uniqueness enforcement. PR #588.
- **FT152 — MagicLink** (2026-05-27): `Nene\Xion\MagicLink` — email-based passwordless auth; single-use time-limited tokens. PR #587.
- **FT151 — Announcement** (2026-05-27): `Nene\Xion\Announcement` — site-wide announcements with scheduling and per-user dismissal. PR #586.
- **FT150 — AlertRule** (2026-05-27): `Nene\Xion\AlertRule` — metric threshold alerts with condition evaluation and event log. PR #585.
- **FT149 — ContentVersion** (2026-05-27): `Nene\Xion\ContentVersion` — append-only content versioning with rollback. PR #584.
- **FT148 — IpBlocklist** (2026-05-27): `Nene\Xion\IpBlocklist` — global IP address blocklist with optional expiry. PR #583.
- **FT147 — ExportJob** (2026-05-27): `Nene\Xion\ExportJob` — async data export job tracking with status lifecycle. PR #582.
- **FT146 — CronLog** (2026-05-27): `Nene\Xion\CronLog` — scheduled task execution log. PR #581.
- **FT145 — ShoppingCart** (2026-05-27): `Nene\Xion\ShoppingCart` — cart item management with qty and price tracking. PR #580.
- **FT144 — ChatMessage** (2026-05-27): `Nene\Xion\ChatMessage` — simple chat room message store with soft-delete. PR #579.
- **FT143 — UserGroup** (2026-05-27): `Nene\Xion\UserGroup` — user group membership with per-member roles. PR #578.
- **FT142 — CacheEntry** (2026-05-27): `Nene\Xion\CacheEntry` — DB-backed key-value cache with TTL. PR #577.
- **FT141 — ConfigStore** (2026-05-27): `Nene\Xion\ConfigStore` — global app key-value config store. PR #576.
- **FT140 — TaskList** (2026-05-27): `Nene\Xion\TaskList` — per-user to-do list with named lists and completion tracking. PR #574.
- **FT139 — GeoFence** (2026-05-27): `Nene\Xion\GeoFence` — named circular geo-fence definition and point containment; Haversine. PR #573.
- **FT138 — CreditLedger** (2026-05-27): `Nene\Xion\CreditLedger` — append-only credit/debit ledger per user; balance guard. PR #572.
- **FT137 — Referral** (2026-05-27): `Nene\Xion\Referral` — referral code generation, attribution, and conversion tracking. PR #571.
- **FT136 — SearchIndex** (2026-05-27): `Nene\Xion\SearchIndex` — lightweight full-text search index with token frequency ranking. PR #570.
- **FT135 — Subscription** (2026-05-27): `Nene\Xion\Subscription` — recurring subscription plan tracking with auto-expiry detection. PR #569.
- **FT134 — Poll** (2026-05-27): `Nene\Xion\Poll` — polls with named options and per-user vote tracking. PR #568.
- **FT133 — CommentThread** (2026-05-27): `Nene\Xion\CommentThread` — threaded comments with soft-delete (revised design). PR #567.
- **FT132 — AbTest** (2026-05-27): `Nene\Xion\AbTest` — A/B test variant assignment (deterministic crc32) and conversion tracking. PR #566.
- **FT131 — MediaMetadata** (2026-05-27): `Nene\Xion\MediaMetadata` — media file registry with key-value metadata sidecar table. PR #565.
- **FT130 — SessionFlash** (2026-05-27): `Nene\Xion\SessionFlash` — DB-backed one-time flash messages for post/redirect/get. PR #564.
- **FT129 — UserPreference** (2026-05-27): `Nene\Xion\UserPreference` — key-value preference store with upsert (revised design). PR #563.
- **FT128 — WebhookDelivery** (2026-05-27): `Nene\Xion\WebhookDelivery` — outbound webhook delivery log with exponential backoff retry. PR #562.
- **FT127 — ConsentLog** (2026-05-27): `Nene\Xion\ConsentLog` — immutable GDPR/CCPA consent audit log; eraseUser GDPR right. PR #561.
- **FT126 — EventLog** (2026-05-27): `Nene\Xion\EventLog` — append-only domain event store with aggregate and event-type queries. PR #560.
- **FT125 — JobQueue** (2026-05-27): `Nene\Xion\JobQueue` — DB-backed background job queue with retry and delay (revised design). PR #559.
- **FT124 — FeatureFlag** (2026-05-27): `Nene\Xion\FeatureFlag` — DB-backed feature flags with global on/off and per-user overrides. PR #558.
- **FT123 — PriceHistory** (2026-05-27): `Nene\Xion\PriceHistory` — append-only price change log with lowest/highest aggregation. PR #557.
- **FT122 — UserSession** (2026-05-27): `Nene\Xion\UserSession` — DB-backed server-side session store with TTL and payload. PR #556.
- **FT121 — IpAllowlist** (2026-05-27): `Nene\Xion\IpAllowlist` — per-resource IP allowlist with CIDR range support. PR #555.
- **FT120 — HealthCheck** (2026-05-27): `Nene\Xion\HealthCheck` — service health status with history and isHealthy aggregate. PR #554.
- **FT119 — SupportTicket** (2026-05-27): `Nene\Xion\SupportTicket` — help-desk ticketing with replies and status lifecycle. PR #553.
- **FT118 — InviteCode** (2026-05-27): `Nene\Xion\InviteCode` — single-use invite codes with quota and expiry. PR #552.
- **FT117 — BookmarkCollection** (2026-05-27): `Nene\Xion\BookmarkCollection` — user-curated bookmark collections with move and clear. PR #551.
- **FT116 — DraftManager** (2026-05-27): `Nene\Xion\DraftManager` — versioned draft persistence with history and prune. PR #550.
- **FT115 — AccessToken** (2026-05-27): `Nene\Xion\AccessToken` — personal access token issue/verify/revoke with SHA-256 hash storage. PR #549.
- **FT114 — TagIndex** (2026-05-27): `Nene\Xion\TagIndex` — flexible entity tagging with multi-tag AND queries. PR #548.
- **FT113 — PresenceChannel** (2026-05-27): `Nene\Xion\PresenceChannel` — heartbeat-based channel presence tracking. PR #547.
- **FT112 — LeaderBoard** (2026-05-27): `Nene\Xion\LeaderBoard` — named scoreboards with upsert, ranking, and windowed queries. PR #546.
- **FT111 — AuditLog** (2026-05-27): `Nene\Xion\AuditLog` — append-only action log with JSON context and actor/resource filtering. PR #545.
- **FT110 — TokenBucket** (2026-05-27): `Nene\Xion\TokenBucket` — DB-backed token bucket for flexible rate limiting. PR #544.
- **FT109 — FileQuarantine** (2026-05-27): `Nene\Xion\FileQuarantine` — file quarantine/release/reject workflow. PR #543.
- **FT108 — SurveyResponse** (2026-05-27): `Nene\Xion\SurveyResponse` — structured survey/quiz answer storage with tally. PR #542.
- **FT107 — NoticeBoard** (2026-05-27): `Nene\Xion\NoticeBoard` — admin announcements with per-user read-acknowledgment. PR #541.
- **FT106 — MediaProcessing** (2026-05-27): `Nene\Xion\MediaProcessing` — async media job tracking with pending/processing/ready/failed states and retry. PR #540.
- **FT105 — EventTicket** (2026-05-27): `Nene\Xion\EventTicket` — event ticketing with capacity management and check-in. PR #539.
- **FT104 — ShortUrl** (2026-05-27): `Nene\Xion\ShortUrl` — URL shortener with auto-code, custom slug, click tracking, and expiry. PR #538.
- **FT103 — CommentThread** (2026-05-27): `Nene\Xion\CommentThread` — threaded comments with soft-delete tombstones (first version). PR #537.
- **FT102 — LoginHistory** (2026-05-27): `Nene\Xion\LoginHistory` — append-only login attempt log with IP, user-agent, and failure tracking. PR #536.
- **FT101 — FriendRequest** (2026-05-27): `Nene\Xion\FriendRequest` — friend request lifecycle with pending/accepted/declined/cancelled states. PR #535.
- **FT100 — OnlineStatus** (2026-05-27): `Nene\Xion\OnlineStatus` — heartbeat-based online/idle/offline tracking. PR #534.
- **FT99 — UserBan** (2026-05-27): `Nene\Xion\UserBan` — user ban/unban with reason, expiry, and full history. PR #533.
- **FT98 — EmailVerification** (2026-05-27): `Nene\Xion\EmailVerification` — token-based email verification with TTL and SHA-256 hash storage. PR #532.
- **FT97 — GeoBlocker** (2026-05-27): `Nene\Xion\GeoBlocker` — country-level access control with blocklist/allowlist modes. PR #531.
- **FT96 — ReactionCounter** (2026-05-27): `Nene\Xion\ReactionCounter` — per-user emoji reactions with toggle and switch semantics. PR #530.
- **FT95 — StorageQuota** (2026-05-27): `Nene\Xion\StorageQuota` — per-owner byte tracking with configurable limits and overflow guard. PR #529.
- **FT94 — ContentSchedule** (2026-05-27): `Nene\Xion\ContentSchedule` — publish/expire windows with draft/published/expired/cancelled lifecycle. PR #528.
- **FT93 — Waitlist** (2026-05-27): `Nene\Xion\Waitlist` — join/invite/confirm/cancel/status/position/count. PR #527.
- **FT92 — PriceList** (2026-05-27): `Nene\Xion\PriceList` — SKU/currency/tier pricing table. PR #526.
- **FT91 — PasswordHistory** (2026-05-27): `Nene\Xion\PasswordHistory` — prevent recent password reuse. PR #525.
- **FT90 — DownloadCounter** (2026-05-27): `Nene\Xion\DownloadCounter` — per-entity download tracking with user limits. PR #524.
- **FT89 — TermConsent** (2026-05-27): `Nene\Xion\TermConsent` — ToS/privacy acceptance tracking with audit trail. PR #523.
- **FT88 — ContentFlag** (2026-05-27): `Nene\Xion\ContentFlag` — user content flagging and moderation queue. PR #522.
- **FT87 — MaintenanceMode** (2026-05-27): `Nene\Xion\MaintenanceMode` — global maintenance flag with user allowlist. PR #521.
- **FT86 — ActivityFeed** (2026-05-27): `Nene\Xion\ActivityFeed` — append-only user event timeline with cursor pagination. PR #520.
- **FT85 — ReferralCode** (2026-05-27): `Nene\Xion\ReferralCode` — referral code generation and conversion tracking (first version). PR #519.
- **FT84 — DeviceToken** (2026-05-27): `Nene\Xion\DeviceToken` — remember-me / trusted-device token with expiry. PR #518.
- **FT83 — ReadProgress** (2026-05-27): `Nene\Xion\ReadProgress` — track user read position with completion flag. PR #517.
- **FT82 — NotificationPreference** (2026-05-27): `Nene\Xion\NotificationPreference` — channel×type opt-in/out with default opt-in. PR #516.
- **FT81 — Wishlist** (2026-05-27): `Nene\Xion\Wishlist` — entity-agnostic per-user saved items with notes. PR #515.
- **FT80 — Poll** (2026-05-27): `Nene\Xion\Poll` — single-choice poll with vote tally and per-user state (first version). PR #514.
- **FT79 — BlockList** (2026-05-27): `Nene\Xion\BlockList` — directional user block with bidirectional query. PR #513.
- **FT78 — NewsletterSubscription** (2026-05-27): `Nene\Xion\NewsletterSubscription` — double opt-in mailing list subscribe/confirm/unsubscribe. PR #512.
- **FT77 — Address Book** (2026-05-27): `Nene\Xion\AddressBook` — add/update/remove/list/setDefault; default swap transaction; partial update. PR #511.
- **FT76 — Two-Factor Authentication (TOTP)** (2026-05-27): `Nene\Xion\TotpAuthenticator` — RFC 6238; generateSecret/verifyCode/otpauthUri/generateBackupCodes; HMAC-SHA1 HOTP. PR #510.
- **FT75 — File Storage Metadata** (2026-05-27): `Nene\Xion\FileMetadata` — register/find/findByOwner/delete; MIME prefix filter; soft delete; storage field. PR #509.
- **FT74 — Geo / Location Helper** (2026-05-27): `Nene\Func\GeoHelper` — distanceKm/distanceMi/boundingBox; Haversine formula; pole-safe lonDelta. PR #508.
- **FT73 — Rate-limited Queue (Simple Job Queue)** (2026-05-27): `Nene\Xion\JobQueue` — enqueue/dequeue/complete/fail; atomic dequeue; delayed jobs. PR #507.
- **FT72 — Bookmark** (2026-05-27): `Nene\Xion\Bookmark` — save/remove/isSaved/list; collection grouping; UNIQUE constraint. PR #506.
- **FT71 — Search History** (2026-05-27): `Nene\Xion\SearchHistory` — upsert dedup, auto-trim, push/recent/clear. PR #505.
- **FT70 — Subscription** (2026-05-27): `Nene\Xion\Subscription` — subscribe/changePlan/cancel/renew; history table. PR #504.
- **FT69 — Comment Thread** (2026-05-27): `Nene\Xion\CommentThread` — depth stored; soft delete (body redacted); depth limit. PR #503.
- **FT68 — Tag Manager** (2026-05-27): `Nene\Xion\TagManager` — M:N entity-tag; syncTags atomic; (entity_type, entity_id). PR #502.
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
- **FT228 — AccessGrant** (2026-05-28): `Nene\Xion\AccessGrant` — time-bounded delegated access; hasAccess() checks active+non-expired; myGrants()/grantsIGave() for both sides; purge(). PR #669.
- **FT227 — ContactMessage** (2026-05-28): `Nene\Xion\ContactMessage` — contact form inbox; STATUS_UNREAD→READ→REPLIED/ARCHIVED; unreadCount(); ORDER BY submitted_at DESC, id DESC. PR #668.
- **FT226 — PageView** (2026-05-28): `Nene\Xion\PageView` — page view analytics; count()/uniqueCount() with period prefix; topUrls()/dailyCounts(); purgeOlderThan(). PR #667.
- **FT225 — HealthCheck** (2026-05-28): `Nene\Xion\HealthCheck` — service health monitoring; latestAll() MAX(id) subquery; avgResponseTime()/failureRate() over last N; STATUS_OK/DEGRADED/FAIL. PR #666.
- **FT224 — MultiLangContent** (2026-05-28): `Nene\Xion\MultiLangContent` — DB-backed multilingual content; UNIQUE (content_key, locale); get() with fallback; cross-driver upsert. PR #665.
- **FT223 — ContentBlock** (2026-05-28): `Nene\Xion\ContentBlock` — ordered page builder blocks; JSON content payload; reorder()/deactivate()/activate()/clear(). PR #664.
- **FT222 — UserSession** (2026-05-28): `Nene\Xion\UserSession` — multi-device session management; SHA-256 token hash; findByToken() auto-marks expired; invalidateAll() force-logout. PR #663.
- **FT221 — EntityAlias** (2026-05-28): `Nene\Xion\EntityAlias` — multiple identifier aliases; UNIQUE (entity_type, alias); idempotent register(); transfer() atomic reassignment; setPrimary(). PR #662.
- **FT220 — BillingUsage** (2026-05-28): `Nene\Xion\BillingUsage` — metered usage tracking; sum()/summary()/overage(); period defaults to current month; reset()/purgeOlderThan(). PR #661.
- **FT219 — SearchSuggestion** (2026-05-28): `Nene\Xion\SearchSuggestion` — type-ahead suggestions; record() cross-driver upsert; suggest() (weight+frequency) DESC; boost(); purge() TTL. PR #660.
- **FT218 — WorkflowInstance** (2026-05-28): `Nene\Xion\WorkflowInstance` — persistent workflow tracking; two-table (instances+transitions); transition()/complete()/cancel(); forEntity(). PR #659.
- **FT217 — NotificationQueue** (2026-05-28): `Nene\Xion\NotificationQueue` — channel-agnostic outbox queue; dequeue() respects scheduled_at+max_attempts; markFailed() CASE WHEN exhaustion. PR #658.
- **FT216 — ProductBundle** (2026-05-28): `Nene\Xion\ProductBundle` — two-table bundle catalog; UNIQUE (bundle_key); addItem() upserts quantity; allActive(). PR #657.
- **FT215 — SurveyResponse** (2026-05-28): `Nene\Xion\SurveyResponse` — two-table survey responses; answers(responseId); answerFrequency() analysis; forSurvey() pagination. PR #656.
- **FT214 — EntityComment** (2026-05-28): `Nene\Xion\EntityComment` — flat comments with soft-delete; edit() blocked on deleted; author-only guards; purge() admin hard-delete. PR #655.
- **FT213 — PointBalance** (2026-05-28): `Nene\Xion\PointBalance` — loyalty point ledger; earn()/spend() (guarded)/expire(); balance() = COALESCE(SUM(delta),0). PR #654.
- **FT212 — AuditLog** (2026-05-28): `Nene\Xion\AuditLog` — compliance append-only entity change tracking; before/after JSON snapshots; forEntity()/byActor()/ofAction()/purgeOlderThan(). PR #653.
- **FT211 — PriceList** (2026-05-28): `Nene\Xion\PriceList` — product price catalog with retail/wholesale/member tiers; effective/expiry windows; integer-cent; cross-driver upsert. PR #652.
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
