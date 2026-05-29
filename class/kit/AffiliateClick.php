<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\DbUpsert;
use Nene\Xion\PdoConnection;
use PDO;

/**
 * AffiliateClick — affiliate click tracking and conversion attribution.
 *
 * Records affiliate referral clicks (each with a unique tracking id) and later
 * marks which ones converted, with an integer-cent value, so payouts and
 * conversion rates can be computed per affiliate. Distinct from `Referral`
 * (FT85, user-to-user referral codes): this is partner/affiliate-program
 * click→conversion attribution.
 *
 * Conversion values are stored as **integer cents** (no floats), consistent
 * with the framework's monetary policy.
 *
 * ## Usage
 *
 * ```php
 * $ac = new AffiliateClick($pdo);
 *
 * $ac->recordClick('partner-7', 'clk_abc123', '/pricing');
 *
 * // Later, when the visitor purchases
 * $ac->convert('clk_abc123', 4990); // $49.90
 *
 * $ac->isConverted('clk_abc123');     // true
 * $ac->stats('partner-7');            // ['clicks'=>1,'conversions'=>1,'revenue'=>4990,'rate'=>1.0]
 * $ac->revenueFor('partner-7');       // 4990
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE affiliate_clicks (
 *     id               INTEGER PRIMARY KEY AUTOINCREMENT,
 *     affiliate        VARCHAR(100) NOT NULL,
 *     click_id         VARCHAR(190) NOT NULL,
 *     landing          VARCHAR(255) NOT NULL DEFAULT '',
 *     converted        INTEGER      NOT NULL DEFAULT 0,
 *     conversion_value INTEGER      NOT NULL DEFAULT 0,
 *     clicked_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     converted_at     DATETIME     NULL,
 *     UNIQUE (click_id)
 * );
 * ```
 */
final class AffiliateClick
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Record an affiliate click. Idempotent per click id (re-recording is ignored).
     *
     * @param  string      $affiliate Affiliate identifier.
     * @param  string      $clickId   Unique click/tracking id.
     * @param  string      $landing   Optional landing path/URL.
     * @param  string|null $asOf      Click time; defaults to now.
     * @throws \InvalidArgumentException on empty affiliate or click id.
     */
    public function recordClick(string $affiliate, string $clickId, string $landing = '', ?string $asOf = null): void
    {
        $affiliate = $this->validate($affiliate, 'Affiliate');
        $clickId   = $this->validate($clickId, 'Click id');

        DbUpsert::run(
            $this->db(),
            table:        'affiliate_clicks',
            data:         ['affiliate' => $affiliate, 'click_id' => $clickId, 'landing' => $landing, 'clicked_at' => $this->ts($asOf)],
            conflictCols: ['click_id'],
            // No updateCols/updateExprs → INSERT ... ON CONFLICT DO NOTHING.
        );
    }

    /**
     * Mark a click as converted with an integer-cent value.
     *
     * @param  string      $clickId    Click id.
     * @param  int         $valueCents Conversion value in cents (>= 0).
     * @param  string|null $asOf       Conversion time; defaults to now.
     * @return bool                    True if newly converted; false if unknown or already converted.
     * @throws \InvalidArgumentException on negative value.
     */
    public function convert(string $clickId, int $valueCents = 0, ?string $asOf = null): bool
    {
        $clickId = $this->validate($clickId, 'Click id');
        if ($valueCents < 0) {
            throw new \InvalidArgumentException('Conversion value must not be negative.');
        }

        $stmt = $this->db()->prepare(
            'UPDATE affiliate_clicks SET converted = 1, conversion_value = ?, converted_at = ?
             WHERE click_id = ? AND converted = 0'
        );
        $stmt->execute([$valueCents, $this->ts($asOf), $clickId]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Whether a click has converted.
     */
    public function isConverted(string $clickId): bool
    {
        $clickId = $this->validate($clickId, 'Click id');
        $stmt    = $this->db()->prepare('SELECT converted FROM affiliate_clicks WHERE click_id = ?');
        $stmt->execute([$clickId]);
        $v = $stmt->fetchColumn();

        return $v !== false && (int)$v === 1;
    }

    /**
     * Number of clicks recorded for an affiliate.
     */
    public function clicksFor(string $affiliate): int
    {
        return $this->scalar('SELECT COUNT(*) FROM affiliate_clicks WHERE affiliate = ?', $affiliate);
    }

    /**
     * Number of converted clicks for an affiliate.
     */
    public function conversionsFor(string $affiliate): int
    {
        return $this->scalar('SELECT COUNT(*) FROM affiliate_clicks WHERE affiliate = ? AND converted = 1', $affiliate);
    }

    /**
     * Total conversion revenue (cents) for an affiliate.
     */
    public function revenueFor(string $affiliate): int
    {
        return $this->scalar('SELECT COALESCE(SUM(conversion_value), 0) FROM affiliate_clicks WHERE affiliate = ?', $affiliate);
    }

    /**
     * Per-affiliate summary: clicks, conversions, revenue (cents), and rate.
     *
     * @return array{clicks:int,conversions:int,revenue:int,rate:float}
     */
    public function stats(string $affiliate): array
    {
        $clicks      = $this->clicksFor($affiliate);
        $conversions = $this->conversionsFor($affiliate);
        $revenue     = $this->revenueFor($affiliate);
        $rate        = $clicks === 0 ? 0.0 : round($conversions / $clicks, 4);

        return ['clicks' => $clicks, 'conversions' => $conversions, 'revenue' => $revenue, 'rate' => $rate];
    }

    /**
     * Delete clicks older than $days. Returns the number removed.
     *
     * @param  int         $days Age threshold (>= 0).
     * @param  string|null $asOf Reference time; defaults to now.
     */
    public function purgeOlderThan(int $days, ?string $asOf = null): int
    {
        if ($days < 0) {
            throw new \InvalidArgumentException('Days must not be negative.');
        }
        $epoch = strtotime($asOf ?? 'now');
        if ($epoch === false) {
            throw new \InvalidArgumentException('Invalid reference time.');
        }
        $cutoff = date('Y-m-d H:i:s', $epoch - $days * 86400);

        $stmt = $this->db()->prepare('DELETE FROM affiliate_clicks WHERE clicked_at < ?');
        $stmt->execute([$cutoff]);

        return $stmt->rowCount();
    }

    // ── private ───────────────────────────────────────────────────────────────

    private function scalar(string $sql, string $affiliate): int
    {
        $affiliate = $this->validate($affiliate, 'Affiliate');
        $stmt      = $this->db()->prepare($sql);
        $stmt->execute([$affiliate]);

        return (int)$stmt->fetchColumn();
    }

    private function validate(string $value, string $label): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new \InvalidArgumentException("{$label} must not be empty.");
        }

        return $value;
    }

    private function ts(?string $asOf): string
    {
        $epoch = strtotime($asOf ?? 'now');
        if ($epoch === false) {
            throw new \InvalidArgumentException('Invalid timestamp.');
        }

        return date('Y-m-d H:i:s', $epoch);
    }

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
