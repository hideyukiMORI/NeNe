# Kit Helper Catalogue

Quick reference for all opt-in helper classes in `class/kit/` (`Nene\Kit`). Grouped by functional domain. See `class/xion/INDEX.md` for framework-core classes. Regenerate with `composer kit:index`.

---

## Auth & Sessions

| Class | Description |
|-------|-------------|
| `AccessToken` | issue, verify, and revoke personal access tokens (PATs). |
| `DeviceFingerprint` | device recognition for fraud detection and trust scoring. |
| `DeviceToken` | push notification device token management per user. |
| `EmailVerification` | token-based email address verification with expiry. |
| `GuestSession` | anonymous visitor session tracking with key-value data bag. |
| `InvitationToken` | Token-based user invitation system. |
| `InviteCode` | single-use invite codes with usage quota. |
| `LoginAttemptTracker` | — |
| `LoginHistory` | append-only log of login attempts with IP, user-agent, and result. |
| `MagicLink` | email-based passwordless authentication tokens. |
| `OAuthToken` | OAuth2 access/refresh token storage with TTL. |
| `OtpChallenge` | one-time password (OTP) challenge issuance and verification. |
| `PasswordExpiry` | Password expiry policy enforcement. |
| `PasswordHistory` | Password history — prevent users from reusing recent passwords. |
| `PasswordPolicy` | configurable password complexity rules per scope. |
| `PasswordResetToken` | Cryptographic helpers for password-reset token workflows. |
| `PersonalAccessToken` | Personal Access Token (PAT) management for user-facing dashboards. |
| `PinCode` | short PIN / OTP issuance with attempt limiting and expiry. |
| `RefreshToken` | DB-backed refresh token management for JWT access token rotation. |
| `ServiceAccount` | machine-to-machine auth credentials with key rotation. |
| `TotpAuthenticator` | TOTP (Time-based One-Time Password) authenticator — RFC 6238 / Google Authenticator compatible. |
| `TwoFactorBackupCode` | one-time recovery codes for 2FA. |
| `UserSession` | multi-device application session management. |

---

## Access Control & Security

| Class | Description |
|-------|-------------|
| `AccessControl` | per-resource subject ACL (access-control list). |
| `AccessGrant` | time-bounded access delegation between entities. |
| `AccessSchedule` | recurring time-window access control for users and resources. |
| `BlockList` | User block list — prevent harassment and unwanted interactions. |
| `GeoBlocker` | country-level access control via a configurable allow/block list. |
| `GeoFence` | define named circular geo-fences and check point containment. |
| `ImpersonationLog` | Admin impersonation session audit log. |
| `IpAllowlist` | per-resource IP allowlist with CIDR range support. |
| `IpBlocklist` | global IP address blocklist with optional expiry. |
| `IpReputation` | running reputation score per IP address. |
| `Pseudonymizer` | stable real-value → pseudonym token mapping for PII. |
| `RedactionRule` | configurable PII/secret masking rules for text. |
| `ResourceLock` | advisory lock on any entity to prevent concurrent edits. |
| `TrustScore` | append-only fraud/trust score per user. |
| `UserBan` | ban / unban users with optional expiry and reason tracking. |

---

## Content & CMS

| Class | Description |
|-------|-------------|
| `Annotation` | per-user text highlights and notes over content ranges. |
| `Announcement` | site-wide announcements with scheduling and per-user dismissal. |
| `Checklist` | ordered checklist items attached to any entity. |
| `CommentThread` | threaded comments attached to any entity. |
| `ContentBlock` | ordered content blocks for page builder / CMS layouts. |
| `ContentDraft` | Content draft lifecycle: draft → published → archived. |
| `ContentFilter` | DB-backed banned word/phrase list with masking. |
| `ContentFlag` | User content flagging and moderation queue. |
| `ContentReport` | User-submitted content moderation reports. |
| `ContentSchedule` | schedule content for future publish and optional expiry. |
| `ContentTag` | flexible tagging of arbitrary entities. |
| `ContentVersion` | content versioning with rollback support. |
| `DraftManager` | versioned draft persistence for any content type. |
| `EmailTemplate` | DB-stored email templates with variable substitution. |
| `EntityComment` | flat comment list attached to any entity. |
| `FaqItem` | FAQ articles with categories, ordering, and helpfulness voting. |
| `KnowledgeBase` | help articles with categories, lifecycle, and view tracking. |
| `MultiLangContent` | multilingual content strings for CMS-style translation. |
| `NoticeBoard` | admin-posted announcements with per-user read-acknowledgment. |
| `Poll` | create polls with options and record per-user votes. |
| `TermGlossary` | DB-backed glossary of terms and definitions. |
| `TextTemplate` | DB-backed general-purpose text templates with variable substitution. |

---

## Users & Profiles

| Class | Description |
|-------|-------------|
| `ActivityFeed` | User-facing activity feed — append-only event timeline. |
| `AddressBook` | Per-user address book with multiple addresses and a default flag. |
| `Bookmark` | Generic per-user bookmark / saved-item manager. |
| `BookmarkCollection` | user-curated collections of bookmarked entities. |
| `ConsentLog` | immutable record of user consent grants and withdrawals. |
| `DailyReward` | once-per-day claimable reward with a consecutive-day streak. |
| `DailyStreak` | Track per-user daily activity streaks. |
| `EntityAlias` | multiple identifier aliases for an entity. |
| `FeatureTour` | per-user state for one-time UI tours / coachmarks. |
| `FollowRelation` | User follow/unfollow relationship management. |
| `FriendRequest` | friend request lifecycle with pending/accepted/declined/cancelled states. |
| `GdprRequest` | GDPR data-subject request tracking. |
| `Mention` | track @-mentions of users within content items. |
| `NotificationPreference` | Notification preference manager — per-user channel × type opt-in/opt-out. |
| `OnlineStatus` | track user presence with heartbeat-based online/idle/offline states. |
| `PinnedItem` | pin entities to the top of a context, in an ordered list. |
| `PresenceChannel` | track which users are currently "in" a named channel. |
| `PresenceTracker` | user online presence and last-seen tracking. |
| `ProfileBadge` | Gamification badge award and management. |
| `ReadProgress` | Track user read progress through content (articles, books, courses, etc.). |
| `SavedSearch` | per-user named search queries. |
| `SearchHistory` | Per-user search history with deduplication and auto-trimming. |
| `SearchIndex` | lightweight full-text search index for any entity. |
| `SearchSuggestion` | type-ahead suggestion management with frequency weighting. |
| `TermConsent` | Track user acceptance of terms of service, privacy policies, and other agreements. |
| `UserActivity` | user last-seen and named action tracking. |
| `UserFeedback` | Collect general user satisfaction feedback (NPS / star ratings + free text). |
| `UserGroup` | user group and team membership management. |
| `UserNote` | admin/staff notes attached to a user record. |
| `UserPreference` | key-value preference store per user with typed defaults. |
| `UserSegment` | user cohort/segment assignment for targeting and analytics. |
| `UserTier` | gamification tier/membership level assignment with history. |

---

## Notifications & Messaging

| Class | Description |
|-------|-------------|
| `ChatMessage` | simple chat room message store with soft-delete. |
| `ContactMessage` | customer contact form inbox management. |
| `DailyDigest` | per-user daily digest item accumulator. |
| `EmailBounce` | email bounce and complaint tracking for delivery health management. |
| `EmailQueue` | DB-backed outgoing email queue with retry and exponential backoff. |
| `EmailSuppression` | do-not-send list for email addresses. |
| `NewsletterSubscription` | Newsletter / mailing-list subscription manager with double opt-in support. |
| `NotificationInbox` | User notification inbox: push, list, mark-as-read, unread count. |
| `NotificationQueue` | channel-agnostic outbox-pattern notification queue. |
| `PushSubscription` | Web Push notification subscription registry. |
| `QuietHours` | per-user do-not-disturb time-of-day window. |
| `RecipientGroup` | mailing list / recipient group management. |
| `Reminder` | user-set future reminders. |
| `SessionFlash` | one-time flash messages stored in DB, consumed on next read. |
| `Snooze` | temporarily hide an item until a wake-up time. |
| `TopicSubscription` | user subscriptions to named topics for notification routing. |

---

## Files & Media

| Class | Description |
|-------|-------------|
| `Attachment` | file attachments linked to any entity. |
| `FileChunk` | chunked file upload tracking and reassembly coordination. |
| `FileMetadata` | File storage metadata management. |
| `FileQuarantine` | quarantine suspicious or policy-violating files with release/reject workflow. |
| `MediaConversionJob` | async media processing job tracking. |
| `MediaGallery` | ordered media item gallery attached to any entity. |
| `MediaMetadata` | store and retrieve metadata for uploaded media files. |
| `MediaProcessing` | track async media conversion/processing jobs. |
| `SignedUrl` | — |
| `StorageQuota` | per-owner storage usage tracking with configurable limits. |

---

## Commerce & Billing

| Class | Description |
|-------|-------------|
| `BillingUsage` | metered usage tracking for billing. |
| `BudgetTracker` | period-based budget allocation and spend tracking. |
| `BulkDiscount` | quantity-tiered percentage discounts per SKU. |
| `CouponCode` | Coupon / promo code management with usage limits and per-user redemption tracking. |
| `CreditLedger` | append-only double-entry credit/debit ledger per user. |
| `CreditNote` | financial credit notes / refund adjustments. |
| `ExchangeRate` | effective-dated currency conversion rate table. |
| `EventTicket` | event ticketing with capacity management and check-in tracking. |
| `ExpenseClaim` | expense reimbursement claims with line items and approval. |
| `GiftCard` | prepaid gift card balance with partial redemption support. |
| `GiftRegistry` | wish-list of desired items that others can claim. |
| `InventoryStock` | product/SKU stock tracking with atomic reserve/release/commit. |
| `OrderLine` | e-commerce order header with line items. |
| `PaymentRecord` | simple payment/transaction ledger. |
| `Payout` | accrue amounts owed to payees and settle them in payout runs. |
| `PointBalance` | user loyalty/reward points with append-only ledger. |
| `PointLedger` | Append-only point / loyalty system with negative-balance prevention. |
| `PriceAlert` | notify users when an item's price drops to their target. |
| `PriceHistory` | append-only price change log for products or any priced entity. |
| `PriceList` | product price catalog with multiple price tiers per SKU. |
| `ProductBundle` | product bundle definitions with included items. |
| `ProductReview` | entity reviews with ratings and helpfulness voting. |
| `PurchaseLimit` | per-user purchase caps per SKU over a rolling window. |
| `Quota` | plan-based resource quota management. |
| `RecurringPayment` | Recurring payment schedule management. |
| `ShippingZone` | region → shipping-zone rate lookup with free-shipping threshold. |
| `ShoppingCart` | cart item management with quantity and price tracking. |
| `Subscription` | track recurring subscription plans per user. |
| `SubscriptionPlan` | user subscription lifecycle management. |
| `StockAlert` | user-defined stock/availability alert registrations. |
| `TaxRate` | tax rate lookup and amount calculation by region and category. |

---

## Analytics & Audit

| Class | Description |
|-------|-------------|
| `AccessLog` | Security-focused resource access log. |
| `AffiliateClick` | affiliate click tracking and conversion attribution. |
| `AlertRule` | metric-based alert rules with threshold evaluation and event log. |
| `ApiUsageLog` | per-API-key request tracking with endpoint and status logging. |
| `AuditLog` | compliance-grade record of who changed what on any entity. |
| `EntitySnapshot` | point-in-time entity state snapshots. |
| `ChangeLog` | human-readable change history for any entity. |
| `CounterMetric` | named metric counters with daily/hourly bucket aggregation. |
| `CronLog` | scheduled task execution log. |
| `DownloadCounter` | Download counter — track file/asset download events. |
| `EventLog` | append-only domain event log for event sourcing–lite patterns. |
| `FunnelStep` | conversion-funnel step completion tracking. |
| `IncidentLog` | IT/service incident tracking with severity and lifecycle. |
| `IntegrationLog` | outbound/inbound API call log with request and response data. |
| `KpiTracker` | KPI / OKR metric tracking with target vs. actual comparison. |
| `PageView` | page and resource view analytics tracking. |
| `ServiceStatus` | public status-page component states with an overall roll-up. |
| `SlaTracker` | SLA/SLO breach detection for timed work items. |
| `UtmCampaign` | UTM marketing-attribution touch capture. |

---

## Social & Community

| Class | Description |
|-------|-------------|
| `AbTest` | A/B test variant assignment and conversion tracking. |
| `Achievement` | progress-tracked, auto-unlocking achievements per user. |
| `Approval` | single-approver workflow (request → approve / reject). |
| `ChangeRequest` | formal RFC / change-management approval workflow. |
| `Endorsement` | peer skill endorsements between users. |
| `EventRsvp` | event attendance management with accept/decline/maybe responses. |
| `FeatureRequest` | user-submitted feature requests with voting and status tracking. |
| `Kudos` | peer recognition / shout-outs between users. |
| `Label` | colored label definitions with entity assignment. |
| `Leaderboard` | Score-based leaderboard with best-score retention, ranking, and personal rank lookup. |
| `Petition` | signature campaign toward a goal. |
| `QuizAttempt` | record and score quiz / assessment attempts per user. |
| `Raffle` | ticket-based prize draw with weighted winner selection. |
| `Reaction` | emoji / symbol reactions on any entity. |
| `ReactionCounter` | emoji/type reactions on any entity with per-user state. |
| `Referral` | referral code generation, attribution, and conversion tracking. |
| `ReferralCode` | Referral code system — generate invite links and track conversions. |
| `ResourceReservation` | time-bounded reservation of a shared resource. |
| `ScoreBoard` | high-score table with personal-best and period tracking. |
| `ShareLink` | shareable links with optional password and expiry. |
| `SupportTicket` | simple help desk ticket queue with reply thread. |
| `SurveyResponse` | form/survey response collection and analysis. |
| `SurveyTemplate` | reusable form/survey template definitions. |
| `TagIndex` | attach tags to any entity and query by tag. |
| `TagManager` | Generic tag/label system for M:N entity-tag relationships. |
| `TaskList` | simple to-do list with per-user tasks and completion tracking. |
| `TeamMembership` | organization/team membership with roles. |
| `TimeEntry` | work time tracking with start/stop/duration. |
| `TimeSlot` | appointment/time slot booking with availability. |
| `VotePoll` | simple polls with named options and one-vote-per-user enforcement. |
| `VotingBooth` | Upvote / downvote system with toggle semantics and per-user vote state. |
| `Waitlist` | manage sign-ups for gated access (beta, launches, events). |
| `WaitlistEntry` | product / feature / event waitlist management. |
| `Watchlist` | users subscribe to watch entities for updates. |
| `Wishlist` | per-user saved items using entity-agnostic (type, id) pairs. |
| `WorkflowInstance` | persistent stateful workflow tracking. |

---

## API & Integration

| Class | Description |
|-------|-------------|
| `ApiDeprecation` | RFC 8594 API deprecation signal helpers. |
| `ApiKey` | API key management: generation, hashed storage, scope-based auth, revocation, rotation. |
| `ApiWebhook` | Webhook subscription endpoint management. |
| `AppVersion` | deployment/release version history tracking. |
| `Cors` | CORS (Cross-Origin Resource Sharing) header utility. |
| `ExportJob` | async data export job tracking. |
| `FeatureFlag` | DB-backed feature flags with global on/off and per-user overrides. |
| `PercentageRollout` | gradual feature rollout by percentage with sticky bucketing. |
| `HealthCheck` | service/component health monitoring log. |
| `Heartbeat` | liveness / dead-man-switch tracking per service. |
| `IdempotencyStore` | DB-backed idempotency key store for POST endpoints. |
| `MaintenanceMode` | Maintenance mode — global on/off flag with allowlist for bypass users. |
| `ShortUrl` | URL shortener with click tracking and optional expiry. |
| `SlugRegistry` | URL slug generation and uniqueness enforcement. |
| `WebhookDelivery` | outbound webhook delivery log with retry tracking. |
| `WebhookSigner` | HMAC-SHA256 webhook signing and verification. |

---

## Tasks & Workflows

| Class | Description |
|-------|-------------|
| `AssetRegistry` | physical and digital asset inventory with assignment tracking. |
| `BusinessCalendar` | working-day calendar with weekend and holiday awareness. |
| `BatchJob` | batch processing job tracking with progress and status lifecycle. |
| `BatchResult` | Accumulates per-item results for a batch operation. |
| `CircuitBreaker` | — |
| `DataImportJob` | CSV/data import job tracking with per-row error recording. |
| `DeadLetterQueue` | parking lot for messages that exhausted their retries. |
| `DistributedLock` | DB-backed distributed lock with TTL, owner enforcement, and stale-lock reclaim. |
| `DocumentLock` | optimistic editing lock for collaborative document editing. |
| `DocumentSignature` | e-signature request workflow with multi-signatory support. |
| `JobQueue` | simple DB-backed background job queue. |
| `LeaveRequest` | employee time-off requests with an approval workflow. |
| `OptimisticLock` | — |
| `QueueTicket` | take-a-number service queue with now-serving tracking. |
| `ReportSchedule` | recurring report definitions with a next-run clock. |
| `RetrySchedule` | exponential-backoff retry tracking for arbitrary operations. |
| `RoundRobinAssigner` | fair rotating assignment across a named pool. |
| `ScheduledTask` | cron-style task schedule registry with last-run tracking. |
| `StockTransfer` | multi-location stock ledger with location-to-location moves. |
| `TokenBucket` | DB-backed token bucket algorithm for flexible rate limiting. |

---

## Infrastructure & DB

| Class | Description |
|-------|-------------|
| `CacheEntry` | DB-backed key-value cache with optional TTL. |
| `ChecksumRegistry` | content integrity / tamper-detection registry. |
| `ConfigStore` | global application key-value configuration store. |
| `DataRetention` | central table→TTL retention policy registry and purge driver. |
| `MaintenanceWindow` | scheduled maintenance windows per service scope. |
| `SequenceNumber` | gapless sequential numbering per named scope. |
| `SystemSetting` | typed global application settings store. |
| `TenantConfig` | per-tenant key-value configuration store. |
| `WeightedPicker` | weighted random selection from a named pool. |

---

*Generated from PHPDoc descriptions. Run `composer kit:index` to refresh after adding classes.*


