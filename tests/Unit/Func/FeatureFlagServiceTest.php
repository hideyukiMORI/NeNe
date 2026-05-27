<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Func;

use Nene\Func\FeatureFlagService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for FeatureFlagService (FT35).
 *
 * Strategy: SQLite :memory: DB — avoids complex multi-prepare mock chaining
 * and gives full SQL fidelity for the three-tier priority logic.
 *
 * Tables created match the schema documented in feature-flags.md:
 *   feature_flags  — flag_name, is_enabled, rollout_pct
 *   flag_overrides — flag_name, user_id, is_enabled
 */
final class FeatureFlagServiceTest extends TestCase
{
    private PDO $db;
    private FeatureFlagService $service;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->db->exec('
            CREATE TABLE feature_flags (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                flag_name   VARCHAR(128) NOT NULL UNIQUE,
                is_enabled  INTEGER NOT NULL DEFAULT 0,
                rollout_pct INTEGER NOT NULL DEFAULT 0
            )
        ');
        $this->db->exec('
            CREATE TABLE flag_overrides (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                flag_name  VARCHAR(128) NOT NULL,
                user_id    INTEGER NOT NULL,
                is_enabled INTEGER NOT NULL DEFAULT 0,
                UNIQUE (flag_name, user_id)
            )
        ');

        $this->service = new FeatureFlagService($this->db);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function insertFlag(string $name, int $isEnabled, int $rolloutPct = 0): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO feature_flags (flag_name, is_enabled, rollout_pct) VALUES (:n, :e, :r)'
        );
        $stmt->execute([':n' => $name, ':e' => $isEnabled, ':r' => $rolloutPct]);
    }

    private function insertOverride(string $name, int $userId, int $isEnabled): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO flag_overrides (flag_name, user_id, is_enabled) VALUES (:n, :u, :e)'
        );
        $stmt->execute([':n' => $name, ':u' => $userId, ':e' => $isEnabled]);
    }

    // ── tests ─────────────────────────────────────────────────────────────────

    public function testFlagNotExistsReturnsFalse(): void
    {
        self::assertFalse($this->service->isEnabled('nonexistent'));
    }

    public function testFlagDisabledNoRolloutReturnsFalse(): void
    {
        $this->insertFlag('my-flag', 0, 0);

        self::assertFalse($this->service->isEnabled('my-flag'));
    }

    public function testFlagEnabledGloballyWithoutUserReturnsTrue(): void
    {
        $this->insertFlag('my-flag', 1);

        self::assertTrue($this->service->isEnabled('my-flag'));
    }

    public function testFlagEnabledGloballyWithUserButNoOverrideReturnsTrue(): void
    {
        $this->insertFlag('my-flag', 1);

        self::assertTrue($this->service->isEnabled('my-flag', 42));
    }

    public function testUserOverrideEnabledOverridesGlobalDisabled(): void
    {
        $this->insertFlag('my-flag', 0);
        $this->insertOverride('my-flag', 42, 1);

        self::assertTrue($this->service->isEnabled('my-flag', 42));
    }

    public function testUserOverrideDisabledActsAsKillSwitchAgainstGlobalEnabled(): void
    {
        $this->insertFlag('my-flag', 1);
        $this->insertOverride('my-flag', 42, 0);

        self::assertFalse($this->service->isEnabled('my-flag', 42));
    }

    public function testRolloutPct100WithUserIdReturnsTrueForEveryone(): void
    {
        $this->insertFlag('rollout-flag', 0, 100);

        self::assertTrue($this->service->isEnabled('rollout-flag', 1));
        self::assertTrue($this->service->isEnabled('rollout-flag', 999));
    }

    public function testRolloutPct0WithUserIdReturnsFalseForEveryone(): void
    {
        $this->insertFlag('rollout-flag', 0, 0);

        self::assertFalse($this->service->isEnabled('rollout-flag', 1));
        self::assertFalse($this->service->isEnabled('rollout-flag', 999));
    }

    public function testRolloutBucketIsDeterministic(): void
    {
        // Pick a rollout_pct that the known bucket for userId=7 either
        // fully includes or fully excludes, then verify consistency.
        $userId   = 7;
        $flagName = 'deterministic-flag';
        $bucket   = abs(crc32($userId . '.' . $flagName)) % 100;

        // Set rollout_pct to bucket+1 so userId=7 is always IN range.
        $this->insertFlag($flagName, 0, $bucket + 1);

        $first  = $this->service->isEnabled($flagName, $userId);
        $second = $this->service->isEnabled($flagName, $userId);
        $third  = $this->service->isEnabled($flagName, $userId);

        self::assertTrue($first);
        self::assertSame($first, $second);
        self::assertSame($first, $third);
    }

    public function testRolloutPctIgnoredWhenUserIdIsNull(): void
    {
        $this->insertFlag('rollout-flag', 0, 100);

        // rollout_pct=100 but no userId → rollout path is skipped → false
        self::assertFalse($this->service->isEnabled('rollout-flag'));
    }

    public function testUserOverrideForOtherUserDoesNotApply(): void
    {
        $this->insertFlag('my-flag', 0);
        $this->insertOverride('my-flag', 99, 1); // override for user 99

        // user 42 has no override; global is disabled → false
        self::assertFalse($this->service->isEnabled('my-flag', 42));
    }
}
