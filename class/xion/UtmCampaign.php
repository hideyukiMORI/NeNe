<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * UtmCampaign — UTM marketing-attribution touch capture.
 *
 * Records UTM parameters (`utm_source`, `utm_medium`, `utm_campaign`,
 * `utm_term`, `utm_content`) seen on a visit, keyed by an opaque visitor id,
 * so marketing can attribute conversions to campaigns and analyse first- vs
 * last-touch. Distinct from `AffiliateClick` (FT282, partner click→conversion)
 * and `Referral` (FT85, user codes): this is campaign-parameter analytics.
 *
 * ## Usage
 *
 * ```php
 * $utm = new UtmCampaign($pdo);
 *
 * $utm->record('visitor-1', ['source' => 'google', 'medium' => 'cpc', 'campaign' => 'spring']);
 * $utm->record('visitor-1', ['source' => 'newsletter', 'medium' => 'email', 'campaign' => 'spring']);
 *
 * $utm->firstTouch('visitor-1');     // google/cpc touch
 * $utm->lastTouch('visitor-1');      // newsletter/email touch
 * $utm->touchesFor('visitor-1');     // full attribution path
 * $utm->countBy('source');           // ['google'=>1,'newsletter'=>1]
 * $utm->campaignTouches('spring');   // 2
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE utm_touches (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     visitor    VARCHAR(190) NOT NULL,
 *     source     VARCHAR(150) NOT NULL,
 *     medium     VARCHAR(150) NOT NULL DEFAULT '',
 *     campaign   VARCHAR(150) NOT NULL DEFAULT '',
 *     term       VARCHAR(150) NOT NULL DEFAULT '',
 *     content    VARCHAR(150) NOT NULL DEFAULT '',
 *     landed_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class UtmCampaign
{
    private const array FIELDS = ['source', 'medium', 'campaign', 'term', 'content'];

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Record a UTM touch for a visitor.
     *
     * @param  string                $visitor Opaque visitor id.
     * @param  array<string,string>  $params  UTM fields (source required;
     *                                         medium/campaign/term/content optional).
     * @param  string|null           $asOf    Touch time; defaults to now.
     * @return int                            New touch id.
     * @throws \InvalidArgumentException on empty visitor or empty source.
     */
    public function record(string $visitor, array $params, ?string $asOf = null): int
    {
        $visitor = $this->validate($visitor, 'Visitor');
        $source  = trim($params['source'] ?? '');
        if ($source === '') {
            throw new \InvalidArgumentException('UTM source must not be empty.');
        }

        $stmt = $this->db()->prepare(
            'INSERT INTO utm_touches (visitor, source, medium, campaign, term, content, landed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $visitor,
            $source,
            trim($params['medium'] ?? ''),
            trim($params['campaign'] ?? ''),
            trim($params['term'] ?? ''),
            trim($params['content'] ?? ''),
            $this->ts($asOf),
        ]);

        return (int)$this->db()->lastInsertId();
    }

    /**
     * Full attribution path for a visitor, oldest touch first.
     *
     * @return array<int,array{source:string,medium:string,campaign:string,term:string,content:string,landed_at:string}>
     */
    public function touchesFor(string $visitor): array
    {
        $visitor = $this->validate($visitor, 'Visitor');
        $stmt    = $this->db()->prepare(
            'SELECT source, medium, campaign, term, content, landed_at FROM utm_touches
             WHERE visitor = ? ORDER BY id ASC'
        );
        $stmt->execute([$visitor]);

        $out = [];
        /** @var array<string,mixed> $row */
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = $this->hydrate($row);
        }

        return $out;
    }

    /**
     * The visitor's first (acquisition) touch, or null.
     *
     * @return array{source:string,medium:string,campaign:string,term:string,content:string,landed_at:string}|null
     */
    public function firstTouch(string $visitor): ?array
    {
        return $this->edgeTouch($visitor, 'ASC');
    }

    /**
     * The visitor's most recent touch, or null.
     *
     * @return array{source:string,medium:string,campaign:string,term:string,content:string,landed_at:string}|null
     */
    public function lastTouch(string $visitor): ?array
    {
        return $this->edgeTouch($visitor, 'DESC');
    }

    /**
     * Number of touches recorded for a campaign.
     */
    public function campaignTouches(string $campaign): int
    {
        $stmt = $this->db()->prepare('SELECT COUNT(*) FROM utm_touches WHERE campaign = ?');
        $stmt->execute([trim($campaign)]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Group touches by a UTM field and count them, busiest first.
     *
     * @param  string $field One of source|medium|campaign|term|content.
     * @return array<string,int> value → count
     * @throws \InvalidArgumentException on an unknown field.
     */
    public function countBy(string $field): array
    {
        if (!in_array($field, self::FIELDS, true)) {
            throw new \InvalidArgumentException("Unknown UTM field: {$field}");
        }

        // $field is whitelisted above, safe to interpolate.
        $stmt = $this->db()->query(
            "SELECT {$field} AS k, COUNT(*) AS c FROM utm_touches GROUP BY {$field} ORDER BY c DESC, k ASC"
        );
        $rows = $stmt === false ? [] : $stmt->fetchAll(PDO::FETCH_ASSOC);

        $out = [];
        /** @var array<string,mixed> $row */
        foreach ($rows as $row) {
            $out[(string)$row['k']] = (int)$row['c'];
        }

        return $out;
    }

    /**
     * Delete touches older than $days. Returns the number removed.
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

        $stmt = $this->db()->prepare('DELETE FROM utm_touches WHERE landed_at < ?');
        $stmt->execute([$cutoff]);

        return $stmt->rowCount();
    }

    // ── private ───────────────────────────────────────────────────────────────

    /**
     * @return array{source:string,medium:string,campaign:string,term:string,content:string,landed_at:string}|null
     */
    private function edgeTouch(string $visitor, string $dir): ?array
    {
        $visitor = $this->validate($visitor, 'Visitor');
        $stmt    = $this->db()->prepare(
            "SELECT source, medium, campaign, term, content, landed_at FROM utm_touches
             WHERE visitor = ? ORDER BY id {$dir} LIMIT 1"
        );
        $stmt->execute([$visitor]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @param  array<string,mixed> $row
     * @return array{source:string,medium:string,campaign:string,term:string,content:string,landed_at:string}
     */
    private function hydrate(array $row): array
    {
        return [
            'source'    => (string)$row['source'],
            'medium'    => (string)$row['medium'],
            'campaign'  => (string)$row['campaign'],
            'term'      => (string)$row['term'],
            'content'   => (string)$row['content'],
            'landed_at' => (string)$row['landed_at'],
        ];
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
