<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * Kudos — peer recognition / shout-outs between users.
 *
 * Lets users give one another public "kudos" (a thank-you / shout-out) with an
 * optional message and category (e.g. 'teamwork', 'innovation'), and reports
 * received/given counts and leaderboards. Distinct from `Endorsement` (FT285,
 * skill endorsements) and `Reaction` (FT, emoji on content): this is
 * free-form peer recognition between people. Self-kudos is disallowed.
 *
 * ## Usage
 *
 * ```php
 * $k = new Kudos($pdo);
 *
 * $k->give(fromUser: 1, toUser: 2, message: 'Saved the release!', category: 'teamwork');
 *
 * $k->receivedCount(2);          // 1
 * $k->givenCount(1);             // 1
 * $k->received(2);               // [['from_user'=>1,'message'=>...,'category'=>'teamwork'], ...]
 * $k->topRecipients(10);         // [['to_user'=>2,'count'=>1], ...]
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE kudos (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     from_user  BIGINT       NOT NULL,
 *     to_user    BIGINT       NOT NULL,
 *     message    VARCHAR(255) NOT NULL DEFAULT '',
 *     category   VARCHAR(50)  NOT NULL DEFAULT '',
 *     created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class Kudos
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Give kudos to another user.
     *
     * @param  int    $fromUser Giver user id.
     * @param  int    $toUser   Recipient user id (must differ from giver).
     * @param  string $message  Optional message.
     * @param  string $category Optional category tag.
     * @return int              New kudos id.
     * @throws \InvalidArgumentException on self-kudos.
     */
    public function give(int $fromUser, int $toUser, string $message = '', string $category = ''): int
    {
        if ($fromUser === $toUser) {
            throw new \InvalidArgumentException('A user cannot give kudos to themselves.');
        }

        $stmt = $this->db()->prepare('INSERT INTO kudos (from_user, to_user, message, category) VALUES (?, ?, ?, ?)');
        $stmt->execute([$fromUser, $toUser, $message, trim($category)]);

        return (int)$this->db()->lastInsertId();
    }

    /**
     * Number of kudos a user has received.
     */
    public function receivedCount(int $toUser): int
    {
        $stmt = $this->db()->prepare('SELECT COUNT(*) FROM kudos WHERE to_user = ?');
        $stmt->execute([$toUser]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Number of kudos a user has given.
     */
    public function givenCount(int $fromUser): int
    {
        $stmt = $this->db()->prepare('SELECT COUNT(*) FROM kudos WHERE from_user = ?');
        $stmt->execute([$fromUser]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Kudos a user has received, newest first.
     *
     * @param  int      $toUser Recipient user id.
     * @param  int|null $limit  Optional cap.
     * @return array<int,array{from_user:int,message:string,category:string}>
     */
    public function received(int $toUser, ?int $limit = null): array
    {
        $sql = 'SELECT from_user, message, category FROM kudos WHERE to_user = ? ORDER BY id DESC';
        if ($limit !== null) {
            $sql .= ' LIMIT ' . max(0, $limit);
        }
        $stmt = $this->db()->prepare($sql);
        $stmt->execute([$toUser]);

        $out = [];
        /** @var array<string,mixed> $row */
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = [
                'from_user' => (int)$row['from_user'],
                'message'   => (string)$row['message'],
                'category'  => (string)$row['category'],
            ];
        }

        return $out;
    }

    /**
     * Per-category counts of kudos a user received, busiest first.
     *
     * @return array<string,int> category → count (blank category as '')
     */
    public function countByCategory(int $toUser): array
    {
        $stmt = $this->db()->prepare(
            'SELECT category, COUNT(*) AS c FROM kudos WHERE to_user = ? GROUP BY category ORDER BY c DESC, category ASC'
        );
        $stmt->execute([$toUser]);

        $out = [];
        /** @var array<string,mixed> $row */
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(string)$row['category']] = (int)$row['c'];
        }

        return $out;
    }

    /**
     * Top recipients by kudos count, most first.
     *
     * @param  int $limit Maximum rows (>= 1).
     * @return array<int,array{to_user:int,count:int}>
     */
    public function topRecipients(int $limit = 10): array
    {
        $limit = max(1, $limit);
        $stmt  = $this->db()->prepare(
            'SELECT to_user, COUNT(*) AS c FROM kudos GROUP BY to_user ORDER BY c DESC, to_user ASC LIMIT ?'
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();

        $out = [];
        /** @var array<string,mixed> $row */
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = ['to_user' => (int)$row['to_user'], 'count' => (int)$row['c']];
        }

        return $out;
    }

    /**
     * Remove a kudos by id. No-op if absent.
     */
    public function remove(int $id): void
    {
        $stmt = $this->db()->prepare('DELETE FROM kudos WHERE id = ?');
        $stmt->execute([$id]);
    }

    // ── private ───────────────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
