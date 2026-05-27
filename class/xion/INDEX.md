# Xion Class Index

Quick reference for all classes in `class/xion/`. Grouped by functional domain.

---

## Auth & Sessions

| Class | Description |
|-------|-------------|
| `AccessToken` | Issue, verify, and revoke personal access tokens (PATs) |
| `AuthSession` | Application session management |
| `BearerAuth` | Optional Bearer-token authentication for agent/MCP clients (ADR-0008) |
| `DeviceFingerprint` | Device recognition for fraud detection and trust scoring |
| `DeviceToken` | Push notification device token management per user |
| `EmailVerification` | Token-based email address verification with expiry |
| `GuestSession` | Anonymous visitor session tracking with key-value data bag |
| `InvitationToken` | Token-based user invitation system |
| `InviteCode` | Single-use invite codes with usage quota |
| `JwtCodec` | JWT encode/decode helpers |
| `LoginAttemptTracker` | Brute-force protection via login attempt counting |
| `LoginHistory` | Append-only log of login attempts with IP, user-agent, and result |
| `MagicLink` | Email-based passwordless authentication tokens |
| `OAuthToken` | OAuth2 access/refresh token storage with TTL |
| `OtpChallenge` | One-time password (OTP) challenge issuance and verification |
| `PasswordExpiry` | Password expiry policy enforcement |
| `PasswordHistory` | Prevent users from reusing recent passwords |
| `PasswordResetToken` | Cryptographic helpers for password-reset token workflows |
| `PersonalAccessToken` | Personal Access Token (PAT) management for user-facing dashboards |
| `PinCode` | Short PIN/OTP issuance with attempt limiting and expiry |
| `RedisSessionHandler` | Redis-backed PHP session handler |
| `RefreshToken` | DB-backed refresh token management for JWT access token rotation |
| `ServiceAccount` | Machine-to-machine auth credentials with key rotation |
| `TotpAuthenticator` | TOTP (RFC 6238 / Google Authenticator compatible) |
| `TwoFactorBackupCode` | One-time recovery codes for 2FA |
| `UserSession` | Multi-device application session management |

---

## Access Control & Security

| Class | Description |
|-------|-------------|
| `AccessControl` | Per-resource subject ACL (access-control list) |
| `AccessGrant` | Time-bounded access delegation between entities |
| `AccessSchedule` | Recurring time-window access control for users and resources |
| `BlockList` | User block list — prevent harassment and unwanted interactions |
| `CsrfProtectionPolicy` | CSRF protection policy helpers |
| `GeoBlocker` | Country-level access control via a configurable allow/block list |
| `GeoFence` | Define named circular geo-fences and check point containment |
| `ImpersonationLog` | Admin impersonation session audit log |
| `IpAllowlist` | Per-resource IP allowlist with CIDR range support |
| `IpBlocklist` | Global IP address blocklist with optional expiry |
| `RedirectGuard` | Open-redirect guard for controllers that need to redirect |
| `ResourceLock` | Advisory lock on any entity to prevent concurrent edits |
| `RoleGuard` | Role-based access gate |
| `TrustScore` | Append-only fraud/trust score per user |
| `UserBan` | Ban/unban users with optional expiry and reason tracking |

---

## Content & CMS

| Class | Description |
|-------|-------------|
| `Announcement` | Site-wide announcements with scheduling and per-user dismissal |
| `Checklist` | Ordered checklist items attached to any entity |
| `CommentThread` | Threaded comments attached to any entity |
| `ContentBlock` | Ordered content blocks for page builder/CMS layouts |
| `ContentDraft` | Content draft lifecycle: draft → published → archived |
| `ContentFilter` | DB-backed banned word/phrase list with masking |
| `ContentFlag` | User content flagging and moderation queue |
| `ContentReport` | User-submitted content moderation reports |
| `ContentSchedule` | Schedule content for future publish and optional expiry |
| `ContentTag` | Flexible tagging of arbitrary entities |
| `ContentVersion` | Content versioning with rollback support |
| `DraftManager` | Versioned draft persistence for any content type |
| `EmailTemplate` | DB-stored email templates with variable substitution |
| `EntityComment` | Flat comment list attached to any entity |
| `FaqItem` | FAQ articles with categories, ordering, and helpfulness voting |
| `KnowledgeBase` | Help articles with categories, lifecycle, and view tracking |
| `MultiLangContent` | Multilingual content strings for CMS-style translation |
| `NoticeBoard` | Admin-posted announcements with per-user read-acknowledgment |
| `Poll` | Create polls with options and record per-user votes |
| `Post` | Blog/content post management |
| `TextTemplate` | DB-backed general-purpose text templates with variable substitution |

---

## Users & Profiles

| Class | Description |
|-------|-------------|
| `ActivityFeed` | User-facing activity feed — append-only event timeline |
| `AddressBook` | Per-user address book with multiple addresses and a default flag |
| `Bookmark` | Generic per-user bookmark/saved-item manager |
| `BookmarkCollection` | User-curated collections of bookmarked entities |
| `ConsentLog` | Immutable record of user consent grants and withdrawals |
| `DailyStreak` | Track per-user daily activity streaks |
| `EntityAlias` | Multiple identifier aliases for an entity |
| `FollowRelation` | User follow/unfollow relationship management |
| `FriendRequest` | Friend request lifecycle (pending/accepted/declined/cancelled) |
| `GdprRequest` | GDPR data-subject request tracking |
| `Mention` | Track @-mentions of users within content items |
| `NotificationPreference` | Per-user channel × type opt-in/opt-out |
| `OnlineStatus` | Track user presence with heartbeat-based online/idle/offline states |
| `PresenceChannel` | Track which users are currently "in" a named channel |
| `PresenceTracker` | User online presence and last-seen tracking |
| `ProfileBadge` | Gamification badge award and management |
| `ReadProgress` | Track user read progress through content |
| `SavedSearch` | Per-user named search queries |
| `SearchHistory` | Per-user search history with deduplication and auto-trimming |
| `TermConsent` | Track user acceptance of terms, privacy policies, and agreements |
| `UserActivity` | User last-seen and named action tracking |
| `UserFeedback` | Collect general user satisfaction feedback (NPS/star ratings + free text) |
| `UserGroup` | User group and team membership management |
| `UserNote` | Admin/staff notes attached to a user record |
| `UserPreference` | Key-value preference store per user with typed defaults |
| `UserSegment` | User cohort/segment assignment for targeting and analytics |
| `UserTier` | Gamification tier/membership level assignment with history |

---

## Notifications & Messaging

| Class | Description |
|-------|-------------|
| `ChatMessage` | Simple chat room message store with soft-delete |
| `ContactMessage` | Customer contact form inbox management |
| `DailyDigest` | Per-user daily digest item accumulator |
| `EmailBounce` | Email bounce and complaint tracking for delivery health management |
| `EmailQueue` | DB-backed outgoing email queue with retry and exponential backoff |
| `MailMessage` | Plain immutable description of one outgoing email |
| `Mailer` | Thin wrapper around Symfony Mailer (ADR-0006) |
| `NewsletterSubscription` | Newsletter/mailing-list subscription manager with double opt-in |
| `NotificationInbox` | User notification inbox: push, list, mark-as-read, unread count |
| `NotificationQueue` | Channel-agnostic outbox-pattern notification queue |
| `PushSubscription` | Web Push notification subscription registry |
| `RecipientGroup` | Mailing list/recipient group management |
| `Reminder` | User-set future reminders |
| `SessionFlash` | One-time flash messages stored in DB, consumed on next read |
| `TopicSubscription` | User subscriptions to named topics for notification routing |

---

## Files & Media

| Class | Description |
|-------|-------------|
| `Attachment` | File attachments linked to any entity |
| `FileChunk` | Chunked file upload tracking and reassembly coordination |
| `FileMetadata` | File storage metadata management |
| `FileQuarantine` | Quarantine suspicious/policy-violating files with release/reject workflow |
| `FileUpload` | Safe wrapper for a single uploaded file (`$_FILES` entry) |
| `MediaConversionJob` | Async media processing job tracking |
| `MediaGallery` | Ordered media item gallery attached to any entity |
| `MediaMetadata` | Store and retrieve metadata for uploaded media files |
| `MediaProcessing` | Track async media conversion/processing jobs |
| `SignedUrl` | Pre-signed URL generation and verification |
| `StorageQuota` | Per-owner storage usage tracking with configurable limits |
| `UploadedFile` | Typed wrapper around a single `$_FILES` entry |

---

## Commerce & Billing

| Class | Description |
|-------|-------------|
| `BillingUsage` | Metered usage tracking for billing |
| `BudgetTracker` | Period-based budget allocation and spend tracking |
| `CouponCode` | Coupon/promo code management with usage limits and per-user redemption |
| `CreditLedger` | Append-only double-entry credit/debit ledger per user |
| `CreditNote` | Financial credit notes/refund adjustments |
| `EventTicket` | Event ticketing with capacity management and check-in tracking |
| `GiftCard` | Prepaid gift card balance with partial redemption support |
| `InventoryStock` | Product/SKU stock tracking with atomic reserve/release/commit |
| `OrderLine` | E-commerce order header with line items |
| `PaymentRecord` | Simple payment/transaction ledger |
| `PointBalance` | User loyalty/reward points with append-only ledger |
| `PointLedger` | Append-only point/loyalty system with negative-balance prevention |
| `PriceHistory` | Append-only price change log for products or any priced entity |
| `PriceList` | Product price catalog with multiple price tiers per SKU |
| `ProductBundle` | Product bundle definitions with included items |
| `ProductReview` | Entity reviews with ratings and helpfulness voting |
| `Quota` | Plan-based resource quota management |
| `RecurringPayment` | Recurring payment schedule management |
| `ShoppingCart` | Cart item management with quantity and price tracking |
| `Subscription` | Track recurring subscription plans per user |
| `SubscriptionPlan` | User subscription lifecycle management |
| `TaxRate` | Tax rate lookup and amount calculation by region and category |

---

## Analytics & Audit

| Class | Description |
|-------|-------------|
| `AccessLog` | Security-focused resource access log |
| `AlertRule` | Metric-based alert rules with threshold evaluation and event log |
| `ApiUsageLog` | Per-API-key request tracking with endpoint and status logging |
| `AuditLog` | Compliance-grade record of who changed what on any entity |
| `AuditLogger` | Append-only audit log writer |
| `ChangeLog` | Human-readable change history for any entity |
| `CounterMetric` | Named metric counters with daily/hourly bucket aggregation |
| `CronLog` | Scheduled task execution log |
| `DownloadCounter` | Track file/asset download events |
| `EventLog` | Append-only domain event log for event sourcing–lite patterns |
| `IncidentLog` | IT/service incident tracking with severity and lifecycle |
| `IntegrationLog` | Outbound/inbound API call log with request and response data |
| `KpiTracker` | KPI/OKR metric tracking with target vs. actual comparison |
| `PageView` | Page and resource view analytics tracking |
| `SlaTracker` | SLA/SLO breach detection for timed work items |

---

## Social & Community

| Class | Description |
|-------|-------------|
| `AbTest` | A/B test variant assignment and conversion tracking |
| `Approval` | Single-approver workflow (request → approve/reject) |
| `ChangeRequest` | Formal RFC/change-management approval workflow |
| `EventRsvp` | Event attendance management with accept/decline/maybe responses |
| `FaqItem` | FAQ articles (see also **Content & CMS**) |
| `FeatureRequest` | User-submitted feature requests with voting and status tracking |
| `Label` | Colored label definitions with entity assignment |
| `Leaderboard` | Score-based leaderboard with best-score retention and ranking |
| `Reaction` | Emoji/symbol reactions on any entity |
| `ReactionCounter` | Emoji/type reactions with per-user state |
| `Referral` | Referral code generation, attribution, and conversion tracking |
| `ReferralCode` | Referral code system — generate invite links and track conversions |
| `ResourceReservation` | Time-bounded reservation of a shared resource |
| `ScoreBoard` | High-score table with personal-best and period tracking |
| `ShareLink` | Shareable links with optional password and expiry |
| `SupportTicket` | Simple help desk ticket queue with reply thread |
| `SurveyResponse` | Form/survey response collection and analysis |
| `SurveyTemplate` | Reusable form/survey template definitions |
| `TagIndex` | Attach tags to any entity and query by tag |
| `TagManager` | Generic tag/label system for M:N entity-tag relationships |
| `TaskList` | Simple to-do list with per-user tasks and completion tracking |
| `TeamMembership` | Organization/team membership with roles |
| `TimeEntry` | Work time tracking with start/stop/duration |
| `TimeSlot` | Appointment/time slot booking with availability |
| `VotePoll` | Simple polls with named options and one-vote-per-user enforcement |
| `VotingBooth` | Upvote/downvote system with toggle semantics and per-user vote state |
| `Waitlist` | Manage sign-ups for gated access (beta, launches, events) |
| `WaitlistEntry` | Product/feature/event waitlist management |
| `Watchlist` | Users subscribe to watch entities for updates |
| `Wishlist` | Per-user saved items using entity-agnostic (type, id) pairs |
| `WorkflowInstance` | Persistent stateful workflow tracking |

---

## API & Integration

| Class | Description |
|-------|-------------|
| `ApiDeprecation` | RFC 8594 API deprecation signal helpers |
| `ApiKey` | API key management: generation, hashed storage, scope-based auth, rotation |
| `ApiResponse` | Standard API response wrapper |
| `ApiWebhook` | Webhook subscription endpoint management |
| `AppVersion` | Deployment/release version history tracking |
| `BearerAuth` | Bearer-token auth (see also **Auth & Sessions**) |
| `Cors` | CORS (Cross-Origin Resource Sharing) header utility |
| `ExportJob` | Async data export job tracking |
| `FeatureFlag` | DB-backed feature flags with global on/off and per-user overrides |
| `HealthCheck` | Service/component health monitoring log |
| `HttpCache` | HTTP cache header utilities for REST endpoints |
| `IdempotencyStore` | DB-backed idempotency key store for POST endpoints |
| `MaintenanceMode` | Global on/off maintenance flag with bypass allowlist |
| `RequestId` | Per-request identifier for end-to-end correlation |
| `ServerTiming` | Server-Timing header helpers |
| `ShortUrl` | URL shortener with click tracking and optional expiry |
| `SlugRegistry` | URL slug generation and uniqueness enforcement |
| `WebhookDelivery` | Outbound webhook delivery log with retry tracking |
| `WebhookSigner` | HMAC-SHA256 webhook signing and verification |

---

## Tasks & Workflows

| Class | Description |
|-------|-------------|
| `AssetRegistry` | Physical and digital asset inventory with assignment tracking |
| `BatchJob` | Batch processing job tracking with progress and status lifecycle |
| `BatchResult` | Accumulates per-item results for a batch operation |
| `CircuitBreaker` | Circuit breaker pattern for external service calls |
| `DataImportJob` | CSV/data import job tracking with per-row error recording |
| `DistributedLock` | DB-backed distributed lock with TTL, owner enforcement, and stale-lock reclaim |
| `DocumentLock` | Optimistic editing lock for collaborative document editing |
| `DocumentSignature` | E-signature request workflow with multi-signatory support |
| `JobQueue` | Simple DB-backed background job queue |
| `OptimisticLock` | Optimistic concurrency control helpers |
| `ScheduledTask` | Cron-style task schedule registry with last-run tracking |
| `TokenBucket` | DB-backed token bucket algorithm for flexible rate limiting |
| `TransactionManager` | Database transaction management helpers |

---

## Infrastructure & DB

| Class | Description |
|-------|-------------|
| `CacheEntry` | DB-backed key-value cache with optional TTL |
| `ConfigStore` | Global application key-value configuration store |
| `DatabaseInstaller` | Database setup and health checks for the sample runtime |
| `DbUpsert` | Cross-driver upsert helper (MySQL + SQLite) — see `DbUpsert::run()` |
| `EnvLoader` | Minimal dotenv-style loader for CLI setup commands |
| `ErrorCode` | Application error code definitions |
| `PdoConnection` | PDO singleton connection factory |
| `SchemaCompiler` | Compile `SchemaDefinition` into MySQL and SQLite DDL |
| `SchemaDefinition` | NeNe's sample-schema source of truth |
| `SystemSetting` | Typed global application settings store |
| `TenantConfig` | Per-tenant key-value configuration store |

---

## HTTP Layer

| Class | Description |
|-------|-------------|
| `ControllerBase` | Base class for all controllers |
| `Cursor` | Cursor-based pagination token |
| `CursorPage` | Cursor-paginated result set metadata |
| `Dispatcher` | Front controller / request dispatcher |
| `HttpEmitter` | HTTP response emitter |
| `HttpResponse` | Mutable HTTP response builder |
| `HttpTermination` | Request/response pipeline termination helpers |
| `JsonResponder` | JSON response shortcut helpers |
| `ModelBase` | Base class for models |
| `OffsetPage` | Paginated result with offset-based (page number) navigation |
| `QueryString` | Query string parsing and building helpers |
| `RedirectGuard` | Open-redirect guard |
| `Request` | HTTP request wrapper |
| `RequestVariables` | Typed request variable extraction |
| `ResponseDecorator` | Cross-cutting response decoration for PHP-generated responses |
| `RouteContext` | Matched route context container |
| `UrlParameter` | URL parameter helpers |
| `View` | Template/view renderer |

---

*Generated from PHPDoc descriptions. Run `ls class/xion/` for the authoritative file list.*
