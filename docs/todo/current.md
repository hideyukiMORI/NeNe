# Current TODO

This file summarizes short-term work for humans and AI agents. GitHub Issues remain the source of truth for actionable work.

## Active

No open Issues. Promotion articles (#178 Qiita / #179 DEV Community / #180 Reddit・HN) are closed — outreach is managed in a separate repository.

The Phase 6 (reviewable small-service delivery) and Phase 7 (field trials) loops have been running continuously. As of 2026-05-29: all non-promotion Issues are closed; FT1–FT316 are complete (FT36 deferred as ADR-class; FT112 closed as conflicting with FT61); ADR-0001–0014 are in place. The Xion helper wave is ongoing — see **Field Trials** for the full log and **Backlog Candidates** for trigger-based future work.

**Feature work has been dormant since 2026-07-06.** What has landed since then is
dependency and CI maintenance only (see 2026-08 below). Whether NeNe stays under
active investment or moves to the dormant-repo inventory is an open decision held
by the owner — it is a strategy question, not a security one.

## Recently Completed

### 2026-08 — dependency + CI maintenance (no feature work)

**2026-08-14 — smarty/smarty 5.8.0 → 5.8.4** (PR #790, `6e2c55e`). Dependabot bump,
`composer.lock` only. Clears CVE-2026-62996 (stream restriction bypass, `>=5.0.0,<5.8.4`)
and CVE-2026-62992 (symlink path traversal, `<4.5.7` | `>=5.0.0,<5.8.2`), both reported
2026-08-07. **Both advisories are resolved** — `composer.lock` on `main` is v5.8.4.

**2026-08-14 — `composer audit` gate in CI** (PR #791 → #792). Added as its **own step**
in `.github/workflows/tests.yml` rather than folded into `composer check`, so the job log
shows whether the audit ran at all: a check that leaves no trace cannot be distinguished
from a check that is missing. Measured 0 findings before adding the gate, so it went in green.

**2026-08-20 — weekly `schedule` on the Tests workflow** (PR #793). The audit step above
only fires on `pull_request` / `push:main`, so advisories published while the repo is quiet
are never seen. NeNe is the proof: no commits between 2026-07-06 and 2026-08-14, and the two
smarty CVEs were reported inside that gap. Adds `schedule` (`25 1 * * 1` — minute allocated
from the fleet-wide free list), `workflow_dispatch`, and a `concurrency` group that includes
`github.event_name` (without it, scheduled runs get cancelled by pushes on the same ref).
🔴 **Known limitation:** GitHub auto-disables scheduled workflows after 60 days of repository
inactivity — the dormant repo that most needs this check is the one where it silently stops.
Threshold is roughly mid-October 2026 counting from 2026-08-14. No in-repo fix; needs external
liveness monitoring.

### 2026-05-28 — FT255–FT264: Xion tenth extended wave (10 trials)

Continuous wave of 10 field trials adding further Xion helper patterns. All PRs #699–#708.

**FT255 — DailyStreak**: `DailyStreak` per-user daily activity streak tracker; checkin() increments on consecutive days, resets on gap; longestStreak() tracks all-time best; reset() preserves longest. PR #699.
**FT256 — UserFeedback**: `UserFeedback` general satisfaction feedback (NPS/star ratings + free text) per context; averageScore(); countByScore() distribution; forUser()/recent()/clearContext(). PR #700.
**FT257 — ProfileBadge**: `ProfileBadge` gamification badge award/revoke; award() idempotent; hasBadge()/userBadges()/usersWithBadge()/countForBadge(); revokeAll() for account cleanup. PR #701.
**FT258 — ContentReport**: `ContentReport` user-submitted content moderation reports; STATUS_PENDING/ACTIONED/DISMISSED; action()/dismiss() guard pending status; forContent()/countByReason(). PR #702.
**FT259 — ApiWebhook**: `ApiWebhook` webhook subscription endpoint registry; auto-generates HMAC secret; rotateSecret(); activate()/deactivate(); forEvent() active subscribers. PR #703.
**FT260 — RecurringPayment**: `RecurringPayment` subscription billing schedule; due() for cron; recordBilling() advances next_billing_date by frequency; pause()/resume()/cancel(); integer-cent amounts. PR #704.
**FT261 — ImpersonationLog**: `ImpersonationLog` admin impersonation session audit; start()/end() guards IS NULL; active(?adminId); history(targetId); purgeOlderThan() skips active sessions. PR #705.
**FT262 — TextTemplate**: `TextTemplate` DB-backed body-only text template for SMS/push/Slack/etc.; {{variable}} substitution; locale fallback to 'en'; cross-driver upsert; activate()/deactivate()/remove(). PR #706.
**FT263 — AccessLog**: `AccessLog` append-only security resource access log; forResource()/byActor()/byAction() queries; purgeOlderThan() TTL; distinct from AuditLog (mutations) and IntegrationLog (HTTP). PR #707.
**FT264 — PasswordExpiry**: `PasswordExpiry` password expiry policy; set() resets clock on change; isExpired()/mustChange(); forceChange() for security incidents; expiringSoon() for proactive notification. PR #708.

### 2026-05-28 — FT239–FT250: Xion ninth extended wave (12 trials)

Continuous wave of 12 field trials adding further Xion helper patterns. All PRs #682–#693.

**FT239 — VotePoll**: `VotePoll` two-table polls with named options; one-vote-per-user cross-driver upsert; vote() validates option against JSON-stored list; close() stops further votes. PR #682.
**FT240 — SubscriptionPlan**: `SubscriptionPlan` user subscription lifecycle; STATUS_TRIAL/ACTIVE/CANCELLED/EXPIRED; isActive() checks status+date range; cancelAtEnd()/cancelNow()/changePlan(); expireStale(). PR #683.
**FT241 — PresenceTracker**: `PresenceTracker` online presence tracking per user+context; cross-driver upsert; isOnline() window-based; online()/onlineIn() for room listing; leave()/purgeOlderThan(). PR #684.
**FT242 — GiftCard**: `GiftCard` partial-redemption gift cards; auto-generates code; STATUS_EXHAUSTED when balance=0; redeem() validates redeemable+sufficient balance; expireStale(). PR #685.
**FT243 — IncidentLog**: `IncidentLog` IT/service incident tracking; SEVERITY_P1–P4; two-table (incident_logs + incident_events); investigate()/resolve()/close() lifecycle; bySeverity(). PR #686.
**FT244 — ChangeRequest**: `ChangeRequest` RFC change-management approval workflow; DRAFT→SUBMITTED→APPROVED/REJECTED→IMPLEMENTED→CLOSED; each transition guards prior status in WHERE clause. PR #687.
**FT245 — BudgetTracker**: `BudgetTracker` period-based budget allocation and spend tracking; allocate()/spend() (OverflowException if exceeds); remaining()/isExhausted(); auto-marks EXHAUSTED at 100%. PR #688.
**FT246 — AssetRegistry**: `AssetRegistry` physical/digital asset inventory; AVAILABLE/ASSIGNED/RETIRED; assign() records history row; unassign() sets returned_at; history() full assignment trail. PR #689.
**FT247 — EventRsvp**: `EventRsvp` event RSVP tracking; RESPONSE_YES/NO/MAYBE; optional capacity limit with overflow guard; guests count; checkin() marks attendance; acceptedCount() includes guests. PR #690.
**FT248 — DocumentSignature**: `DocumentSignature` e-signature request workflow; multi-signatory support; sign() auto-completes when all signed; decline() immediately cancels; pendingFor() pending inbox. PR #691.
**FT249 — FeatureRequest**: `FeatureRequest` product feature requests with upvoting; OPEN→PLANNED→IN_PROGRESS→SHIPPED; upvote()/downvote() with one-per-user enforcement; hasVoted(); top() by vote count. PR #692.
**FT250 — KpiTracker**: `KpiTracker` KPI/OKR metric tracking; define() with optional target/period/unit; record() time-series data points; progress() returns {actual, target, pct}; purgeOlderThan() per definition. PR #693.

### 2026-05-28 — FT229–FT238: Xion eighth extended wave (10 trials)

Continuous wave of 10 field trials adding further Xion helper patterns. All PRs #671–#680.

**FT229 — SurveyTemplate**: `SurveyTemplate` two-table form/survey template definitions with typed questions (text/textarea/number/select/radio/checkbox); inactive by default. PR #671.
**FT230 — RecipientGroup**: `RecipientGroup` mailing list / recipient group management; UNIQUE slug; addMember() cross-driver upsert; isMember()/groupsFor(). PR #672.
**FT231 — EntitySnapshot**: `EntitySnapshot` point-in-time entity state snapshots; findAt() nearest snapshot; list() returns metadata only; purgeOlderThan() TTL. PR #673.
**FT232 — StockAlert**: `StockAlert` stock/availability alert registrations; cron batch via pendingTriggers()/markTriggered(); dismiss() silences without delete. PR #674.
**FT233 — ResourceReservation**: `ResourceReservation` time-bounded reservation with overlap detection; isAvailable() excludeId for re-booking; forResource() range query. PR #675.
**FT234 — ScoreBoard**: `ScoreBoard` high-score table with personal-best per player; top() derives MAX(score) per player; rank() counts players with higher best. PR #676.
**FT235 — CreditNote**: `CreditNote` financial credit notes against any entity; integer-cent amounts; PENDING→APPLIED/VOIDED lifecycle; pendingTotal() sum. PR #677.
**FT236 — DeviceFingerprint**: `DeviceFingerprint` device recognition for fraud detection; SHA-256 hash storage; seen() cross-driver upsert increments seen_count; isTrusted()/isBlocked(). PR #678.
**FT237 — SlaTracker**: `SlaTracker` SLA/SLO breach detection; start() computes deadline from duration; pause/resume accumulates paused_seconds; breached() cron query. PR #679.
**FT238 — MediaConversionJob**: `MediaConversionJob` async media processing FIFO queue; nextPending()/start()/complete()/fail()/retry(); attempts counter for backoff. PR #680.

### 2026-05-28 — FT211–FT228: Xion seventh extended wave (18 trials)

Continuous wave of 18 field trials adding further Xion helper patterns. All PRs #652–#669.

**FT211 — PriceList**: `PriceList` product price catalog with retail/wholesale/member tiers; integer-cent amounts; effective/expiry date windows; cross-driver upsert. PR #652.
**FT212 — AuditLog**: `AuditLog` compliance-grade append-only entity change tracking; before/after JSON snapshots; forEntity()/byActor()/ofAction()/purgeOlderThan(). PR #653.
**FT213 — PointBalance**: `PointBalance` loyalty/reward point ledger; earn()/spend() (guarded against negative)/expire(); balance() = COALESCE(SUM(delta),0). PR #654.
**FT214 — EntityComment**: `EntityComment` flat (non-threaded) comments on any entity; soft-delete; edit tracking; author-only guards; purge() for admin hard-delete. PR #655.
**FT215 — SurveyResponse**: `SurveyResponse` two-table form/survey response collection; per-response answers; answerFrequency() for analysis. PR #656.
**FT216 — ProductBundle**: `ProductBundle` two-table product bundle catalog; inactive by default; addItem() upserts quantity; allActive(). PR #657.
**FT217 — NotificationQueue**: `NotificationQueue` channel-agnostic outbox-pattern queue; dequeue() respects scheduled_at and max_attempts; markFailed() CASE WHEN exhaustion. PR #658.
**FT218 — WorkflowInstance**: `WorkflowInstance` persistent stateful workflow tracking; two-table (instances + transitions); complete()/cancel() terminal states. PR #659.
**FT219 — SearchSuggestion**: `SearchSuggestion` type-ahead suggestion management; record() cross-driver upsert; suggest() by (weight+frequency) desc; boost(); purge(). PR #660.
**FT220 — BillingUsage**: `BillingUsage` metered usage tracking for billing; sum()/summary()/overage(); period defaults to current month; reset()/purgeOlderThan(). PR #661.
**FT221 — EntityAlias**: `EntityAlias` multiple identifier aliases per entity; UNIQUE (entity_type, alias); idempotent register(); transfer() atomic reassignment. PR #662.
**FT222 — UserSession**: `UserSession` multi-device session management; SHA-256 token hash storage; findByToken() auto-marks expired; invalidateAll() force-logout. PR #663.
**FT223 — ContentBlock**: `ContentBlock` ordered page builder content blocks; JSON content payload; reorder()/deactivate()/activate(); clear() entity bulk-delete. PR #664.
**FT224 — MultiLangContent**: `MultiLangContent` DB-backed multilingual content; UNIQUE (content_key, locale); get() with fallback locale; cross-driver upsert. PR #665.
**FT225 — HealthCheck**: `HealthCheck` service health monitoring log; latestAll() MAX(id) subquery; avgResponseTime()/failureRate() over last N checks. PR #666.
**FT226 — PageView**: `PageView` page view analytics; count()/uniqueCount() with period prefix filter; topUrls()/dailyCounts(); purgeOlderThan(). PR #667.
**FT227 — ContactMessage**: `ContactMessage` contact form inbox; STATUS_UNREAD→READ→REPLIED/ARCHIVED lifecycle; unreadCount(); purgeArchived() TTL. PR #668.
**FT228 — AccessGrant**: `AccessGrant` time-bounded access delegation; hasAccess() checks active+non-expired; myGrants()/grantsIGave() for both sides. PR #669.

### 2026-05-27 — FT201–FT210: Xion sixth extended wave (10 trials)

Continuous wave of 10 field trials adding further Xion helper patterns. All PRs #641–#650.

**FT201 — InventoryStock**: `InventoryStock` product/SKU stock tracking with atomic three-phase reserve/release/commit; inventory_log audit trail. PR #641.
**FT202 — TimeEntry**: `TimeEntry` work time tracking with start/stop timers and manual entries; totalSeconds() per project. PR #642.
**FT203 — TimeSlot**: `TimeSlot` appointment/time-slot booking with capacity; atomic UPDATE WHERE booked < capacity prevents overbooking. PR #643.
**FT204 — IntegrationLog**: `IntegrationLog` external API call log with service/endpoint/status/body/duration; errors() surfaces non-2xx; deleteOlderThan() TTL. PR #644.
**FT205 — GdprRequest**: `GdprRequest` GDPR data-subject request tracking (access/rectification/erasure/portability); pending→acknowledged→completed|rejected. PR #645.
**FT206 — WaitlistEntry**: `WaitlistEntry` product/feature waitlist with queue positioning; inviteNext() batch; position() 1-based rank. PR #646.
**FT207 — TaxRate**: `TaxRate` regional tax rate lookup by region+category; cross-driver upsert; calculateCents()/totalWithTaxCents() integer-cent safety. PR #647.
**FT208 — DeviceToken**: `DeviceToken` push notification device token management per user; register() reactivates existing; deleteInactive() cleanup. PR #648.
**FT209 — ContentTag**: `ContentTag` flexible entity tagging; tags normalised to lowercase slugs; cloud() tag→count map; entitiesWith() reverse lookup. PR #649.
**FT210 — ResourceLock**: `ResourceLock` advisory entity locking with TTL; acquire() returns int|null; extend(); releaseExpired() cron cleanup. PR #650.

### 2026-05-27 — FT196–FT200: Xion fifth extended wave (5 trials)

Continuous wave of 5 field trials adding further Xion helper patterns. All PRs #635–#639.

**FT196 — DataImportJob**: `DataImportJob` two-table CSV/data import job tracking; per-row error recording; pending→validating→processing→done|failed. PR #635.
**FT197 — TopicSubscription**: `TopicSubscription` user subscriptions to named topics for notification routing; subscribersOf() fanout; distinct from NewsletterSubscription. PR #636.
**FT198 — OrderLine**: `OrderLine` e-commerce order with line items (two-table); integer-cent amounts; pending→confirmed→shipped→delivered|cancelled. PR #637.
**FT199 — AppVersion**: `AppVersion` deployment/release version history per environment; current() latest; history() paginated newest-first. PR #638.
**FT200 — UserSegment**: `UserSegment` user cohort/segment assignment (two-table); idempotent addUser; segmentsFor/usersIn for targeting. PR #639.

### 2026-05-27 — FT186–FT195: Xion fourth extended wave (10 trials)

Continuous wave of 10 field trials adding further Xion helper patterns. All PRs #623–#633.

**FT186 — SupportTicket**: `SupportTicket` help-desk ticketing with ticket + reply tables; status/priority lifecycle. PR #624.
**FT187 — EventLog**: `EventLog` append-only domain event log (event sourcing–lite); aggregate/actor/type queries. PR #625.
**FT188 — DailyDigest**: `DailyDigest` per-user digest item accumulator with grouping and batch markSent. PR #626.
**FT189 — AccessControl**: `AccessControl` per-resource subject ACL; idempotent grant; complements global RBAC. PR #627.
**FT190 — TwoFactorBackupCode**: `TwoFactorBackupCode` one-time 2FA recovery codes; SHA-256 hash storage; generate() invalidates old codes. PR #628.
**FT191 — SystemSetting**: `SystemSetting` typed global app settings (string/int/bool/json) with categories; cross-driver upsert. PR #629.
**FT192 — MediaGallery**: `MediaGallery` ordered media item gallery per entity; cover photo designation; distinct from Attachment. PR #630.
**FT193 — ProductReview**: `ProductReview` entity reviews with 1–5 star ratings; approval workflow; helpfulness voting. PR #631.
**FT194 — FaqItem**: `FaqItem` FAQ articles with category, position ordering, keyword search, helpfulness voting. PR #632.
**FT195 — UserTier**: `UserTier` gamification tier assignment with two-table design (current + history); hasEverHad(). PR #633.

### 2026-05-27 — FT176–FT185: Xion third extended wave (10 trials)

Continuous wave of 10 field trials adding further Xion helper patterns. All PRs #613–#622.

**FT176 — Label**: `Label` two-table colored label system (labels + assignments); idempotent attach/detach; forEntity/entitiesWithLabel. PR #613.
**FT177 — Checklist**: `Checklist` ordered checklist items on any entity; check/uncheck; percent() 0–100; pending() filter. PR #614.
**FT178 — Reaction**: `Reaction` emoji/symbol reactions per user per entity; toggle (add/remove); counts() grouped by type. PR #615.
**FT179 — Mention**: `Mention` @-mention tracking with unread inbox, markRead, unreadCount, mentionedIn. PR #616.
**FT180 — Watchlist**: `Watchlist` entity watch subscriptions; toggle; watcherIds(); watching(userId) for notification routing. PR #617.
**FT181 — Attachment**: `Attachment` file attachment metadata linked to any entity; totalBytes(); forUser(). PR #618.
**FT182 — SavedSearch**: `SavedSearch` per-user named search queries with upsert, usage tracking, and scope filtering. PR #619.
**FT183 — ContentFilter**: `ContentFilter` DB-backed banned word list; detect/mask with optional whole-word mode; in-memory cache. PR #620.
**FT184 — Reminder**: `Reminder` user-set future reminders; due() for cron-poll; markSent; purgeSent. PR #621.
**FT185 — KnowledgeBase**: `KnowledgeBase` help articles with draft→published→archived lifecycle; search; recordView. PR #622.

### 2026-05-27 — FT166–FT175: Xion second extended wave (10 trials)

Continuous wave of 10 field trials adding new Xion helper patterns. All PRs #602–#611.

**FT166 — Quota**: `Quota` plan-based resource quota management; unlimited (0), optional TTL reset windows, auto-reset on consume(). PR #602.
**FT167 — ShareLink**: `ShareLink` shareable links with optional password protection, view limits, and TTL expiry; password_hash omitted from resolve(). PR #603.
**FT168 — Approval**: `Approval` single-approver request/approve/reject workflow; only pending records can be decided; cannot re-decide. PR #604.
**FT169 — TeamMembership**: `TeamMembership` team/org membership with per-member roles; idempotent add (upserts role); cross-driver upsert. PR #605.
**FT170 — TrustScore**: `TrustScore` append-only fraud/trust score per user; signed deltas; below() for risk queries; purgeOlderThan(). PR #606.
**FT171 — PaymentRecord**: `PaymentRecord` payment/transaction ledger; integer-cent amounts; pending→paid|failed, paid→refunded; cannot re-decide. PR #607.
**FT172 — PinCode**: `PinCode` short PIN/OTP (4–8 digits) with attempt limiting, expiry, and SHA-256 hash storage; one active PIN per user+purpose. PR #608.
**FT173 — GuestSession**: `GuestSession` anonymous visitor sessions with JSON key-value data bag; login promotion via linkUser(); TTL with touch(). PR #609.
**FT174 — ScheduledTask**: `ScheduledTask` cron-style task schedule registry; due() returns overdue tasks; complements CronLog. PR #610.
**FT175 — UserNote**: `UserNote` admin/staff notes attached to user records; supports pinning (pinned-first ordering); CRM/support use case. PR #611.

### 2026-05-27 — FT141–FT165: Xion extended helper wave (25 trials)

Continuous wave of 25 field trials extending the Xion helper library. All PRs #576–#600. FT112 (LeaderBoard) closed as conflicting with existing FT61 Leaderboard class.

**FT141 — ConfigStore**: `ConfigStore` global app key-value config with namespace support. PR #576.
**FT142 — CacheEntry**: `CacheEntry` DB-backed TTL cache; zero extra infrastructure fallback. PR #577.
**FT143 — UserGroup**: `UserGroup` user group membership with per-member roles. PR #578.
**FT144 — ChatMessage**: `ChatMessage` simple chat room message store with soft-delete. PR #579.
**FT145 — ShoppingCart**: `ShoppingCart` cart item management with qty and integer-cent price tracking. PR #580.
**FT146 — CronLog**: `CronLog` scheduled task execution log with status lifecycle. PR #581.
**FT147 — ExportJob**: `ExportJob` async data export job tracking with status lifecycle. PR #582.
**FT148 — IpBlocklist**: `IpBlocklist` global IP address blocklist with optional expiry. PR #583.
**FT149 — ContentVersion**: `ContentVersion` append-only content versioning with rollback. PR #584.
**FT150 — AlertRule**: `AlertRule` metric threshold alerts with condition evaluation and event log. PR #585.
**FT151 — Announcement**: `Announcement` site-wide announcements with scheduling and per-user dismissal. PR #586.
**FT152 — MagicLink**: `MagicLink` email-based passwordless auth; single-use time-limited tokens. PR #587.
**FT153 — SlugRegistry**: `SlugRegistry` namespace-scoped URL slug generation and uniqueness enforcement. PR #588.
**FT154 — EmailQueue**: `EmailQueue` DB-backed outgoing email queue with exponential backoff retry. PR #589.
**FT155 — DocumentLock**: `DocumentLock` collaborative editing lock with TTL; holder-only refresh/release. PR #590.
**FT156 — ApiUsageLog**: `ApiUsageLog` per-API-key request tracking with endpoint/status/latency logging. PR #591.
**FT157 — OAuthToken**: `OAuthToken` OAuth2 access/refresh token storage with rotation; SHA-256 hashed. PR #592.
**FT158 — UserActivity**: `UserActivity` user last-seen tracking and named action event log. PR #593.
**FT159 — EmailTemplate**: `EmailTemplate` DB-stored email templates with {{variable}} substitution and locale support. PR #594.
**FT160 — BatchJob**: `BatchJob` batch processing job tracking with progress lifecycle. PR #595.
**FT161 — ServiceAccount**: `ServiceAccount` machine-to-machine auth credentials with key rotation. PR #596.
**FT162 — ChangeLog**: `ChangeLog` human-readable entity change history with before/after JSON snapshots. PR #597.
**FT163 — PushSubscription**: `PushSubscription` Web Push notification subscription registry. PR #598.
**FT164 — CounterMetric**: `CounterMetric` named metric counters with daily/hourly bucket aggregation. PR #599.
**FT165 — FileChunk**: `FileChunk` chunked file upload tracking and reassembly coordination. PR #600.

### 2026-05-27 — FT78–FT140: Xion helper wave (63 trials)

Continuous single-day wave of 63 field trials covering social, content, security, analytics, and infrastructure helpers. All PRs #512–#574 merged.

**FT78 — NewsletterSubscription**: `NewsletterSubscription` double opt-in mailing list subscribe/confirm/unsubscribe. PR #512.
**FT79 — BlockList**: `BlockList` directional user block with bidirectional query. PR #513.
**FT80 — Poll**: `Poll` single-choice poll with vote tally and per-user state (first version). PR #514.
**FT81 — Wishlist**: `Wishlist` entity-agnostic per-user saved items with notes. PR #515.
**FT82 — NotificationPreference**: `NotificationPreference` channel×type opt-in/out with default opt-in. PR #516.
**FT83 — ReadProgress**: `ReadProgress` track user read position with completion flag. PR #517.
**FT84 — DeviceToken**: `DeviceToken` remember-me / trusted-device token with expiry. PR #518.
**FT85 — ReferralCode**: `ReferralCode` referral code generation and conversion tracking (first version). PR #519.
**FT86 — ActivityFeed**: `ActivityFeed` append-only user event timeline with cursor pagination. PR #520.
**FT87 — MaintenanceMode**: `MaintenanceMode` global maintenance flag with user allowlist. PR #521.
**FT88 — ContentFlag**: `ContentFlag` user content flagging and moderation queue. PR #522.
**FT89 — TermConsent**: `TermConsent` ToS/privacy acceptance tracking with audit trail. PR #523.
**FT90 — DownloadCounter**: `DownloadCounter` per-entity download tracking with user limits. PR #524.
**FT91 — PasswordHistory**: `PasswordHistory` prevent recent password reuse. PR #525.
**FT92 — PriceList**: `PriceList` SKU/currency/tier pricing table. PR #526.
**FT93 — Waitlist**: `Waitlist` join/invite/confirm/cancel/status/position/count. PR #527.
**FT94 — ContentSchedule**: `ContentSchedule` publish/expire windows with draft/published/expired/cancelled lifecycle. PR #528.
**FT95 — StorageQuota**: `StorageQuota` per-owner byte tracking with configurable limits and overflow guard. PR #529.
**FT96 — ReactionCounter**: `ReactionCounter` per-user emoji reactions with toggle and switch semantics. PR #530.
**FT97 — GeoBlocker**: `GeoBlocker` country-level access control with blocklist/allowlist modes. PR #531.
**FT98 — EmailVerification**: `EmailVerification` token-based email verification with TTL and SHA-256 hash storage. PR #532.
**FT99 — UserBan**: `UserBan` user ban/unban with reason, expiry, and full history. PR #533.
**FT100 — OnlineStatus**: `OnlineStatus` heartbeat-based online/idle/offline tracking. PR #534.
**FT101 — FriendRequest**: `FriendRequest` friend request lifecycle with pending/accepted/declined/cancelled states. PR #535.
**FT102 — LoginHistory**: `LoginHistory` append-only login attempt log with IP, user-agent, and failure tracking. PR #536.
**FT103 — CommentThread**: `CommentThread` threaded comments with soft-delete tombstones (first version). PR #537.
**FT104 — ShortUrl**: `ShortUrl` URL shortener with auto-code, custom slug, click tracking, and expiry. PR #538.
**FT105 — EventTicket**: `EventTicket` event ticketing with capacity management and check-in. PR #539.
**FT106 — MediaProcessing**: `MediaProcessing` async media job tracking with pending/processing/ready/failed states and retry. PR #540.
**FT107 — NoticeBoard**: `NoticeBoard` admin announcements with per-user read-acknowledgment. PR #541.
**FT108 — SurveyResponse**: `SurveyResponse` structured survey/quiz answer storage with tally. PR #542.
**FT109 — FileQuarantine**: `FileQuarantine` file quarantine/release/reject workflow. PR #543.
**FT110 — TokenBucket**: `TokenBucket` DB-backed token bucket for flexible rate limiting. PR #544.
**FT111 — AuditLog**: `AuditLog` append-only action log with JSON context and actor/resource filtering. PR #545.
**FT112 — LeaderBoard**: `LeaderBoard` named scoreboards with upsert, ranking, and windowed queries. PR #546.
**FT113 — PresenceChannel**: `PresenceChannel` heartbeat-based channel presence tracking. PR #547.
**FT114 — TagIndex**: `TagIndex` flexible entity tagging with multi-tag AND queries. PR #548.
**FT115 — AccessToken**: `AccessToken` personal access token issue/verify/revoke with SHA-256 hash storage. PR #549.
**FT116 — DraftManager**: `DraftManager` versioned draft persistence with history and prune. PR #550.
**FT117 — BookmarkCollection**: `BookmarkCollection` user-curated bookmark collections with move and clear. PR #551.
**FT118 — InviteCode**: `InviteCode` single-use invite codes with quota and expiry. PR #552.
**FT119 — SupportTicket**: `SupportTicket` help-desk ticketing with replies and status lifecycle. PR #553.
**FT120 — HealthCheck**: `HealthCheck` service health status with history and isHealthy aggregate. PR #554.
**FT121 — IpAllowlist**: `IpAllowlist` per-resource IP allowlist with CIDR range support. PR #555.
**FT122 — UserSession**: `UserSession` DB-backed server-side session store with TTL and payload. PR #556.
**FT123 — PriceHistory**: `PriceHistory` append-only price change log with lowest/highest aggregation. PR #557.
**FT124 — FeatureFlag**: `FeatureFlag` DB-backed feature flags with global on/off and per-user overrides. PR #558.
**FT125 — JobQueue**: `JobQueue` DB-backed background job queue with retry and delay (revised design). PR #559.
**FT126 — EventLog**: `EventLog` append-only domain event store with aggregate and event-type queries. PR #560.
**FT127 — ConsentLog**: `ConsentLog` immutable GDPR/CCPA consent audit log; eraseUser GDPR right. PR #561.
**FT128 — WebhookDelivery**: `WebhookDelivery` outbound webhook delivery log with exponential backoff retry. PR #562.
**FT129 — UserPreference**: `UserPreference` key-value preference store with upsert (revised design). PR #563.
**FT130 — SessionFlash**: `SessionFlash` DB-backed one-time flash messages for post/redirect/get. PR #564.
**FT131 — MediaMetadata**: `MediaMetadata` media file registry with key-value metadata sidecar table. PR #565.
**FT132 — AbTest**: `AbTest` A/B test variant assignment (deterministic crc32) and conversion tracking. PR #566.
**FT133 — CommentThread**: `CommentThread` threaded comments with soft-delete (revised design). PR #567.
**FT134 — Poll**: `Poll` polls with named options and per-user vote tracking (revised design). PR #568.
**FT135 — Subscription**: `Subscription` recurring subscription plan tracking with auto-expiry detection. PR #569.
**FT136 — SearchIndex**: `SearchIndex` lightweight full-text search index with token frequency ranking. PR #570.
**FT137 — Referral**: `Referral` referral code generation, attribution, and conversion tracking (revised design). PR #571.
**FT138 — CreditLedger**: `CreditLedger` append-only credit/debit ledger per user; balance guard. PR #572.
**FT139 — GeoFence**: `GeoFence` named circular geo-fence definition and point containment; Haversine. PR #573.
**FT140 — TaskList**: `TaskList` per-user to-do list with named lists and completion tracking. PR #574.

### 2026-05-27 — FT73–FT77: Infrastructure + auth + geo + identity wave (5 trials)

**FT73 — Job Queue**: `JobQueue` enqueue/dequeue/complete/fail; atomic dequeue; delayed jobs. PR #507.
**FT74 — Geo Helper**: `GeoHelper` distanceKm/distanceMi/boundingBox; Haversine formula. PR #508.
**FT75 — File Metadata**: `FileMetadata` register/find/findByOwner/delete; soft delete; MIME filter. PR #509.
**FT76 — TOTP Authenticator**: `TotpAuthenticator` RFC 6238; generateSecret/verifyCode/otpauthUri/backup codes. PR #510.
**FT77 — Address Book**: `AddressBook` add/update/remove/list/setDefault; atomic default swap. PR #511.

### 2026-05-27 — FT68–FT72: Extended social patterns wave (5 trials)

**FT68 — Tag Manager**: `TagManager` M:N entity-tag; syncTags atomic; (entity_type, entity_id). PR #502.
**FT69 — Comment Thread**: `CommentThread` depth stored; soft delete (body redacted); depth limit. PR #503.
**FT70 — Subscription**: `Subscription` subscribe/changePlan/cancel/renew; history table. PR #504.
**FT71 — Search History**: `SearchHistory` upsert dedup; auto-trim; push/recent/clear. PR #505.
**FT72 — Bookmark**: `Bookmark` save/remove/isSaved/list; collection grouping; UNIQUE constraint. PR #506.

### 2026-05-27 — FT63–FT67: Social + loyalty patterns wave (5 trials)

**FT63 — User Follow System**: `FollowRelation` directed follow/unfollow; isMutual; self-follow prevention. PR #497.
**FT64 — User Preferences**: `UserPreference` key-value store; getInt/getBool type casting; upsert. PR #498.
**FT65 — Personal Access Token**: `PersonalAccessToken` ability-based auth; last_used_at tracking; pat_ prefix. PR #499.
**FT66 — Coupon / Promo Code**: `CouponCode` usage limits; per-user redemption; atomic redeem; two-table. PR #500.
**FT67 — Point / Loyalty System**: `PointLedger` append-only ledger; earn/spend/balance/history; negative-balance prevention. PR #501.

### 2026-05-27 — FT51–FT62: Extended patterns wave (12 trials)

Second wave of field trials covering security, social, and content management patterns.

**FT51 — i18n**: `I18n` static message catalog; `{name}` placeholders; locale fallback. PR #485.
**FT52 — Event Dispatcher**: `EventDispatcher` in-process pub-sub; listen/emit/removeListener. PR #486.
**FT53 — Personal Data Export**: `PersonalDataExport` GDPR Art.20 portability; provider registration. PR #487.
**FT54 — JSON Schema Validator**: `JsonSchemaValidator` zero-dependency JSON Schema subset validator. PR #488.
**FT55 — Distributed Lock**: `DistributedLock` DB-backed TTL lock; stale reclaim; owner-enforced release. PR #489.
**FT56 — API Key Management**: `ApiKey` prefix+SHA-256; admin⊃write⊃read scopes; rotation. PR #490.
**FT57 — JWT Refresh Token**: `RefreshToken` rotation with replay attack detection via revokeAll(). PR #491.
**FT58 — Notification Inbox**: `NotificationInbox` read_at nullable; idempotent mark-read. PR #492.
**FT59 — Voting System**: `VotingBooth` upvote/downvote toggle; score. PR #493.
**FT60 — Content Draft**: `ContentDraft` draft/publish/archive lifecycle; SQL transition guards. PR #494.
**FT61 — Leaderboard**: `Leaderboard` best-score retention; rank via COUNT; limit clamping. PR #495.
**FT62 — Invitation Token**: `InvitationToken` 256-bit token; expiry-before-status; owner cancel. PR #496.

### 2026-05-27 — FT25–FT50: NENE2 parity wave + extended patterns (26 trials)

Single-day wave of 26 field trials covering NENE2-equivalent patterns plus additional production-readiness helpers. All PRs #458–#483 merged.

**FT25 — Cursor pagination**: `Cursor` + `CursorPage`; base64url token; keyset SQL (created_at, id). PR #458.
**FT26 — Soft delete**: `DataMapperBase::SOFT_DELETE`; softDelete/restore/findTrashed/purge; deleted_at auto-filter. PR #459.
**FT27 — Optimistic locking / ETag**: `OptimisticLock`; parseIfMatch/requireVersion/conflict; 412/428. PR #460.
**FT28 — Rate limiting**: `RateLimiter` + Redis storage; fixed-window INCR+EXPIRE; X-RateLimit-* headers; 429. PR #461.
**FT29 — State machine**: `WorkflowDefinition`; code-driven transition map; assertTransition → 409. PR #462.
**FT30 — JWT HS256**: `JwtCodec`; pure PHP HMAC-SHA256; issue/decode/require; alg:none defence. PR #463.
**FT31 — RBAC**: `RoleGuard`; JWT claims-based require/requireAny/has; 401 vs 403. PR #464.
**FT32 — Password reset**: `PasswordResetToken`; random_bytes + SHA-256; isExpired/expiresAt. PR #465.
**FT33 — Audit log**: `AuditLogger`; append-only audit_log; PDO injection; silent on PDOException. PR #466.
**FT34 — Webhook signing**: `WebhookSigner`; Stripe-style t=ts,v1=hmac; hash_equals; generateSecret(). PR #467.
**FT35 — Feature flags**: `FeatureFlagService`; DB-backed; user override → global → rollout%; crc32 bucket. PR #468.
**FT37 — Idempotency keys**: `IdempotencyStore`; INSERT IGNORE/OR IGNORE; X-Idempotency-Key / X-Idempotent-Replayed. PR #469.
**FT38 — Full-text search**: `SearchQuery`; escapeLike/likePattern/sanitizeFts/normalize; FTS5 doc. PR #470.
**FT39 — API versioning / deprecation**: `ApiDeprecation`; RFC 8594 Deprecation/Sunset/Link; ADR-0013. PR #471.
**FT40 — Batch operations**: `BatchResult`; addSuccess/addFailure/httpStatus; 200/207/422 partial-success. PR #472.
**FT41 — Account lockout**: `LoginAttemptTracker`; DB-backed failure counter; locks at threshold; ACCOUNT-LOCKED 423. PR #473.
**FT42 — Signed URL**: `SignedUrl`; HMAC-SHA256 sign/verify/requireValid; expiry; SIGNED-URL-EXPIRED 410. PR #474.
**FT43 — Circuit breaker**: `CircuitBreaker`; CLOSED/OPEN/HALF-OPEN state machine; DB-backed; CIRCUIT-OPEN 503. PR #475.
**FT44 — HTTP cache headers**: `HttpCache`; sendCacheControl/sendLastModified/isNotModified/send304; conditional GET. PR #476.
**FT45 — CORS**: `Cors`; sendHeaders/handlePreflight/isAllowed; wildcard vs explicit origins. PR #477.
**FT46 — File upload**: `FileUpload`; require/load/validateSize/validateMime/moveTo; finfo MIME detection. PR #479.
**FT47 — Tree helper**: `TreeHelper`; build/ancestors/descendants/depth/flatten for adjacency-list trees. PR #478.
**FT48 — Offset pagination**: `OffsetPage` + `PaginationHelper`; page envelope; window() UI helper. PR #480.
**FT49 — Money value object**: `Money`; immutable integer-based; add/subtract/multiply/round/format (JPY/USD/EUR). PR #482.
**FT50 — Input validation**: `Validator`; required/maxLength/minLength/email/url/integer/in/regex; VALIDATION-FAILED 422. PR #483.

FT36 (background jobs) deferred as ADR-class — trigger: real "POST takes 8s" friction event.

### 2026-05-21 — FT3 / FT4 / FT5 / FT6 + infra + checklists

Single-day wave of trial-driven improvements across the framework, documentation, and process.

**FT3 (authlog — REST auth + CSRF):** report PR #250; follow-ups #251–#253 → PR #254 (Reference Client docs), PR #255 (self-discovering contract test), PR #256 (ADR-0003 + generic `ApiFailureEnvelope` migration). All Issues closed.

**FT4 (smarty-html — server-rendered HTML pages):** report PR #262; follow-ups #263–#267 → PR #268 (compile cache tip), #269 (`location()` URI normalize), #270 (asset auto-discovery convention), #272 (Smarty escape × `nl2br`), #273 (HTML form POST tutorial section). All Issues closed.

**FT5 (protected-notes — auth × HTML cross):** report PR #275; follow-ups #276–#282 → PR #283 (bootstrap script sanity check), #284 (`LOGOUT_URI` env override), #285 (reference-client.md session-regen note), #286 (URL controller naming docs), #287 (ADR-0004 + `unauthorizedRedirect()` hook), #288 (CI health-wait timeout), #289 (HTML form CSRF helper), #290 (HTML login form tutorial). All Issues closed.

**FT6 (cli-tooling — installer scripts):** report PR #293; follow-ups #294–#298 → PR #299 (`composer setup` shortcut), #300 (`--env=PATH` strict), #301 (`initSQLite.php` `--yes` / `--help`), #302 (schema 3-way parity docs), #303 (canonical / legacy CLI docs + new `docs/development/cli.md`). All Issues closed.

**Process import:** PR #291 added `docs/review/` self-review checklists (8 files: REST controller, HTML controller, database, OpenAPI contract, docs/ADR, release/CI, field-trial report, README index) adapted from sibling NENE2's pattern. Referenced from `docs/workflow.md` and `docs/CONTRIBUTING.md`.

**Stale Issue cleanup:** #234 (FT2 trial Issue, historically open), #145 (AI-readable reference implementation goal), #165 (reviewable Controller-Service-Mapper proof) — closed with explanatory comments. The goals these Issues encoded were effectively delivered through the FT3–FT6 tutorial additions, the `docs/review/` checklists, ADR-0003, and ADR-0004.

**Infra changes that landed alongside the trial loops:**

- `tools/nene-ft-new.sh` — one-shot FT clone bootstrap (port offset, `.claude/settings.local.json`, `.claude/CLAUDE.md`, PLAN skeleton). Sanity check (PR #283) blocks the "run-from-clone-cwd" footgun.
- `field-trial` GitHub label — created and applied retroactively to 18 historical Issues.
- `main` branch protection — required status checks (`unit`, `HTTP runtime smoke (Docker)`); the improvement loops were merged via `gh pr merge --auto`.
- CI workflow — health-wait now requires `Data.healthStatus = ok` (not just HTTP 200) and the timeout is 120s (PR #259 / #288).
- `~/.claude/settings.json` (developer-side) — broad dev-tool wildcards replaced the narrow per-command permission accumulation that had grown in `NeNe/.claude/settings.local.json`.
- `jq` installed on the development host.

### Earlier 2026-05

- #217: Add `?self` type to singleton `$instance` in 6 classes; add `: void` to `IndexController::indexAction()`; fix `@return` PHPDoc in `Dispatcher`.
- #212: Add native type declarations to all properties in remaining `class/xion/` base classes (ModelBase, DataMapperBase, DataModelBase, RouteContext, TransactionManager, ApiResponse, Log, ErrorCode); propagate `array` type to `Todo`/`User` subclass `$schema`.
- #213: Add `: never` to `__clone()` in 6 singleton classes, `: void` to `PdoConnection::__destruct()`, `: mixed` to `DataModelBase::__get()`.
- #207: Add native type declarations to all properties in `ControllerBase`; move `$TITLE`/`$HEADER_TITLE` initialization to constructor.
- #206: Replace `file_put_contents` in `ModelBase::accessLog()` with `$this->LOGGER->info()` to unify logging via Monolog.
- #205: Add `(string)` casts to `preg_replace` in `DataMapperBase`; add missing `: void`, `: mixed`, `: static` return types across `xion/` base classes.
- #201: Further reduce Phan baseline from 13 to 6 issues; fix DataMapperBase::update() bug using isValid() instead of validate() in error message.
- #199: Add return type declaration to preAction() overrides in IndexController and SessionController.
- #195: Update actions/checkout from v4 to v6 in CI workflow.
- #193: Remove dead `$controller` and `$action` properties from `ControllerBase`.
- #190: Clean up `.gitignore`, Vue.js comment in `View`, and completed Issues in `roadmap.md`.
- #189: Improve type declarations and reduce Phan baseline in `class/xion/`.
- #187: Remove the controller-level Smarty template fallback.
- #185: Document Smarty template, CSS, and JavaScript placement conventions.
- #177: Prepare the Zenn renovation-story article.
- #176: Prepare Composer and Packagist-facing metadata for public discovery while keeping `git clone` as the recommended install path.
- #174: Clarify that `git clone` is the recommended install path for now.
- #172: Clarify that the review-cost message is about implementation-style variance, not outside reviewer scarcity.
- #164: Update the public entry to present NeNe as a reviewable small-service PHP framework.
- #169: Position the publication strategy document as a public OSS release case study.
- #167: Add the review-cost angle around modern pattern learning and implementation-style variance.
- #163: Reframe the next phase around reducing code review cost through consistent implementation conventions.
- #162: Document the publication and outreach strategy for NeNe after `v0.2.0`.
- #160: Document AI self-review checklists and service-layer implementation standards.
- #158: Prepare the `v0.2.0` release notes and runtime version.
- #154, #155, #156: Clean up release-blocking code quality concerns in `ControllerBase` and `DataMapperBase`.
- #152: Add the canonical transaction pattern to the sample page tutorial.
- #150: Document the canonical transaction pattern for service tutorials and coding standards.
- #148: Add a database transaction boundary for multi-step mapper work.
- #135: Refactor `ControllerBase` responsibilities into a testable CSRF protection boundary.
- #144: Adjust Phase 6 around reference implementations and small-service delivery.
- #142: Document AI readability and small-service delivery as the next project phase.
- #140: Clarify Docker development database credentials for phpMyAdmin and MySQL.
- #138: Add phpMyAdmin with the darkwolf theme to the Docker development environment.
- #136: Change the project license to MIT.
- #133: Document the `v0.1.0` release milestone and prepare the first framework tag.
- #131: Show the runtime environment label in the development health check card.
- #129: Show the database type in the development health check card.
- #127: Remove the tracked generated SQLite database artifact.
- #125: Clarify the SQLite3 initialization command in install documentation.
- #123: Load the repository-root `.env` before web runtime initialization.
- #121: Add server install database setup CLI and runtime health check.
- #120: Add a traditional Apache/PHP server install guide and public documentation page.
- #116: Expand HTTP runtime coverage for explicit routing, REST method boundaries, and JSON-only responses.
- #108: Remove legacy JSONP output and move JSON handling to the response boundary.
- #106: Update roadmap and milestones to reflect current status and architecture policy.
- #104: Clarify NeNe's renovation philosophy and target audience.
- #102: Add a service implementation tutorial for pages, REST endpoints, database-backed features, OpenAPI, and tests.
- #99: Prepare documentation/sample sections for the home side menu: `Authentication`, `Routing Guide`, and `OpenAPI`.
- #98: Refresh TODO from roadmap, milestones, and Issue state.
- #88: Fix request variable storage and add boundary tests.
- #87: Decide and apply non-200 HTTP status policy for authentication failures.
- #86: Route Dispatcher errors through the shared JSON error responder.
- #85: Safely encode template data-object JSON for inline script output.
- #84: Harden legacy callback output before the JSON-only policy superseded it.
- #83: Add CSRF protection to cookie-authenticated state-changing APIs.
- #82: Hash stored passwords and clean up sample credentials.
- #81: Harden authentication session lifecycle and cookie attributes.
- #80: Control public error display by environment.
- #78: Parse OpenAPI runtime contract tests with `symfony/yaml`.
- #76: Add PHP CS Fixer configuration and formatting scripts.
- #74: Add Phan baseline and repeatable static analysis configuration.
- #70: Document the OpenAPI runtime contract test parser policy.
- #68: Update the SQLite initializer so the TODO sample also works with SQLite fallback.
- #66: Expand first-reader comments in `ini/xSystemIni.php`.
- #64: Organize `ini/xSystemIni.php` constants, runtime definitions, ordering, and comments.
- #62: Remove unused legacy styles from `htdocs/css/common.css`.
- #60: Simplify `view/source` for the React sample layout.
- #58: Remove unused Vue-era assets and templates.
- #56: Extend HTTP runtime tests and CI-oriented checks.
- #54: Add HTTP runtime smoke tests.
- #52: Polish Swagger UI with a consistent dark theme.
- #50: Add starter OpenAPI contract and Swagger UI.
- #16: Add PHPUnit test foundation and first pure function tests.
- #8: Rename default branch from `master` to `main`.
- #6: Add project documentation, AI guide, coding standards, workflow, roadmap, TODO, milestones, and ADR foundation.

## Next

FT1–FT175 complete (FT36 deferred as ADR-class; FT112 closed as conflicting with FT61). The Xion helper wave is complete. Remaining work is trigger-based — see `docs/field-trials/candidates.md` for the 保留候補 list.

## Field Trials

The methodology is documented in `docs/field-trials/README.md` and `docs/templates/field-trial-report.md`. Trials are cloned into `../NeNe-FT/ft{N}-{topic}/`.

When a trial is run, summarize it here with the format below, then move the block to `Recently Completed` once all follow-up Issues are merged or closed.

```
## FT{N} — {topic}

- Report: `docs/field-trials/YYYY-MM-field-trial-{N}.md`
- Baseline: {NeNe ref}
- Findings: F-1 (severity / decision / Issue #), F-2 (...), ...
```

### Recently Completed

- **FT1** — baseline trial from `ft1-bookmarklog`. Pivoted from a Bookmark+Tag implementation when baseline phase produced enough findings to fill the trial on its own. Closed 5 Issues: #222 (PdoConnection runtime fatal hotfix), #224 (CI runtime smoke job), #225 (`composer test:http` preflight), #226 (`NENE_HTTP_BASE_URL` docs), #227 (Docker `safe.directory`). Report: `docs/field-trials/2026-05-field-trial-1.md`. The originally planned Bookmark+Tag scope shifts to FT2.
- **FT2** — Bookmark + Tag M:N CRUD trial from `ft2-bookmark-tag`. Two-entity REST service with transactional relation diff, dual DB schema (SQLite + MySQL), OpenAPI extension, 6 new HTTP smoke tests. 7 findings. Follow-up Issues #237, #238, #239, #240, #241–#244 closed; F-5 escalated in FT3 and resolved via ADR-0003. Report: `docs/field-trials/2026-05-field-trial-2.md`.
- **FT3** — auth-protected Memo CRUD from `ft3-authlog`. Session + CSRF flow against REST. 6 findings; 3 follow-up Issues #251–#253 closed by PRs #254 / #255 / #256. ADR-0003 (generic OpenAPI failure envelope) born from F-1 (escalation of FT2 F-5). Report: `docs/field-trials/2026-05-field-trial-3.md`.
- **FT4** — server-rendered Note CRUD from `ft4-smarty-html`. Smarty + asset auto-discovery + HTML form POST. 9 findings; 5 follow-up Issues #263–#267 closed by PRs #268 / #269 / #270 / #272 / #273. Report: `docs/field-trials/2026-05-field-trial-4.md`.
- **FT5** — protected-notes (auth × HTML cross) from `ft5-protected-notes`. HTML login form + CSRF helper + per-controller redirect target. 10 findings; 7 follow-up Issues #276–#282 closed by PRs #283 / #284 / #285 / #286 / #287 / #289 / #290 (#287 introduced ADR-0004 `unauthorizedRedirect()` hook). Side-effect: PR #288 bumped CI health-wait timeout to 120s. Report: `docs/field-trials/2026-05-field-trial-5.md`.
- **FT6** — CLI installer tooling (`cli/initSQLite.php`, `cli/setupDatabase.php`) from `ft6-cli-tooling`. First CLI-only trial. 7 findings (5 actionable); 5 follow-up Issues #294–#298 closed by PRs #299 / #300 / #301 / #302 / #303 (including new `docs/development/cli.md` and `composer setup` shortcut). Report: `docs/field-trials/2026-05-field-trial-6.md`.

## Backlog Candidates

Active candidates are maintained in `docs/field-trials/candidates.md`. Summary:

### Trigger-based candidates

- **FT36** — バックグラウンドジョブ (deferred, ADR-class, large). Trigger: real "POST takes 8s" friction.
- **Observability** — OpenTelemetry traceparent/tracestate. Trigger: OTel collector needed in real deploy.
- **Structural: Multi-tenancy** — row-scoped tenant isolation. Trigger: real B2B SaaS deploy.
- **Structural: Constraint-changes ADR** — promote ADR-0009 "warning-only" to hard constraint path. Trigger: 3+ operator friction cases.

### Real-world surface trials (still unrun)

- **error pages** — 404 / 500 templates, HTML vs REST error rendering. Small.
- **production-mode deployment probe** — `NENE_APP_ENV=production` + `NENE_APP_DEBUG=0`. Medium.
- **OpenAPI authoring workflow** — end-to-end new entity with current tooling. Small.

### General code-quality

- PHPDoc accuracy and native types across `class/xion/`.
- GitHub Actions: review Node.js deprecation warnings when v24-ready actions are available.
