<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * PasswordPolicy — configurable password complexity rules per scope.
 *
 * Stores complexity requirements (minimum length and character-class flags)
 * per named scope and validates candidate passwords against them. Distinct
 * from `PasswordExpiry` (FT264, *when* a password must change) and
 * `PasswordHistory` (FT91, preventing *reuse*): this governs *what makes a
 * password acceptable* at set-time.
 *
 * When no policy row exists for a scope, the built-in defaults apply
 * (min length {@see PasswordPolicy::DEFAULT_MIN_LENGTH}, no character-class
 * requirements), so `validate()` is always safe to call.
 *
 * ## Usage
 *
 * ```php
 * $pp = new PasswordPolicy($pdo);
 *
 * $pp->setPolicy('admin', minLength: 12, requireUpper: true, requireDigit: true, requireSymbol: true);
 *
 * $pp->validate('admin', 'short');
 * // ['too_short', 'need_upper', 'need_digit', 'need_symbol']
 *
 * $pp->isValid('admin', 'Sup3rSecret!');   // true
 * $pp->validate('unconfigured', 'abcdefgh'); // [] — default min-8 satisfied
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE password_policies (
 *     id             INTEGER PRIMARY KEY AUTOINCREMENT,
 *     scope          VARCHAR(100) NOT NULL,
 *     min_length     INTEGER      NOT NULL DEFAULT 8,
 *     require_upper  INTEGER      NOT NULL DEFAULT 0,
 *     require_lower  INTEGER      NOT NULL DEFAULT 0,
 *     require_digit  INTEGER      NOT NULL DEFAULT 0,
 *     require_symbol INTEGER      NOT NULL DEFAULT 0,
 *     updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (scope)
 * );
 * ```
 */
final class PasswordPolicy
{
    public const int DEFAULT_MIN_LENGTH = 8;

    public const string VIOLATION_TOO_SHORT   = 'too_short';
    public const string VIOLATION_NEED_UPPER  = 'need_upper';
    public const string VIOLATION_NEED_LOWER  = 'need_lower';
    public const string VIOLATION_NEED_DIGIT  = 'need_digit';
    public const string VIOLATION_NEED_SYMBOL = 'need_symbol';

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Set (or replace) the policy for a scope. Idempotent per scope.
     *
     * @param  string $scope         Policy scope (e.g. 'admin').
     * @param  int    $minLength     Minimum length (>= 1).
     * @param  bool   $requireUpper  Require an uppercase letter.
     * @param  bool   $requireLower  Require a lowercase letter.
     * @param  bool   $requireDigit  Require a digit.
     * @param  bool   $requireSymbol Require a non-alphanumeric character.
     * @throws \InvalidArgumentException on empty scope or $minLength < 1.
     */
    public function setPolicy(
        string $scope,
        int $minLength = self::DEFAULT_MIN_LENGTH,
        bool $requireUpper = false,
        bool $requireLower = false,
        bool $requireDigit = false,
        bool $requireSymbol = false,
    ): void {
        $scope = $this->validateScope($scope);
        if ($minLength < 1) {
            throw new \InvalidArgumentException('Minimum length must be at least 1.');
        }

        DbUpsert::run(
            $this->db(),
            table:        'password_policies',
            data:         [
                'scope'          => $scope,
                'min_length'     => $minLength,
                'require_upper'  => $requireUpper ? 1 : 0,
                'require_lower'  => $requireLower ? 1 : 0,
                'require_digit'  => $requireDigit ? 1 : 0,
                'require_symbol' => $requireSymbol ? 1 : 0,
            ],
            conflictCols: ['scope'],
            updateCols:   ['min_length', 'require_upper', 'require_lower', 'require_digit', 'require_symbol'],
            updateExprs:  ['updated_at' => 'CURRENT_TIMESTAMP'],
        );
    }

    /**
     * Return the effective policy for a scope (stored or built-in default).
     *
     * @param  string $scope Policy scope.
     * @return array{min_length:int,require_upper:bool,require_lower:bool,require_digit:bool,require_symbol:bool}
     */
    public function getPolicy(string $scope): array
    {
        $scope = $this->validateScope($scope);

        $stmt = $this->db()->prepare(
            'SELECT min_length, require_upper, require_lower, require_digit, require_symbol
             FROM password_policies WHERE scope = ?'
        );
        $stmt->execute([$scope]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return [
                'min_length'     => self::DEFAULT_MIN_LENGTH,
                'require_upper'  => false,
                'require_lower'  => false,
                'require_digit'  => false,
                'require_symbol' => false,
            ];
        }

        return [
            'min_length'     => (int)$row['min_length'],
            'require_upper'  => (bool)$row['require_upper'],
            'require_lower'  => (bool)$row['require_lower'],
            'require_digit'  => (bool)$row['require_digit'],
            'require_symbol' => (bool)$row['require_symbol'],
        ];
    }

    /**
     * Validate a password against a scope's policy.
     *
     * @param  string $scope    Policy scope.
     * @param  string $password Candidate password.
     * @return array<int,string> Violation codes (empty = valid).
     */
    public function validate(string $scope, string $password): array
    {
        $policy     = $this->getPolicy($scope);
        $violations = [];

        if (mb_strlen($password) < $policy['min_length']) {
            $violations[] = self::VIOLATION_TOO_SHORT;
        }
        if ($policy['require_upper'] && preg_match('/[A-Z]/', $password) !== 1) {
            $violations[] = self::VIOLATION_NEED_UPPER;
        }
        if ($policy['require_lower'] && preg_match('/[a-z]/', $password) !== 1) {
            $violations[] = self::VIOLATION_NEED_LOWER;
        }
        if ($policy['require_digit'] && preg_match('/[0-9]/', $password) !== 1) {
            $violations[] = self::VIOLATION_NEED_DIGIT;
        }
        if ($policy['require_symbol'] && preg_match('/[^A-Za-z0-9]/', $password) !== 1) {
            $violations[] = self::VIOLATION_NEED_SYMBOL;
        }

        return $violations;
    }

    /**
     * Whether a password satisfies a scope's policy.
     */
    public function isValid(string $scope, string $password): bool
    {
        return $this->validate($scope, $password) === [];
    }

    /**
     * Remove a scope's policy (reverts it to the built-in default). No-op if absent.
     */
    public function remove(string $scope): void
    {
        $scope = $this->validateScope($scope);
        $stmt  = $this->db()->prepare('DELETE FROM password_policies WHERE scope = ?');
        $stmt->execute([$scope]);
    }

    // ── private ───────────────────────────────────────────────────────────────

    private function validateScope(string $scope): string
    {
        $scope = trim($scope);
        if ($scope === '') {
            throw new \InvalidArgumentException('Scope must not be empty.');
        }

        return $scope;
    }

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
