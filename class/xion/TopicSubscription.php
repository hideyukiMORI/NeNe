<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * TopicSubscription — user subscriptions to named topics for notification routing.
 *
 * Users subscribe to named topics (e.g. "product.update", "system.alert",
 * "blog.post"). Callers query subscribers to route notifications.
 *
 * Distinct from:
 * - NewsletterSubscription (mailing list with double opt-in flow)
 * - NotificationPreference (channel × type opt-in matrix)
 *
 * ## Usage
 *
 * ```php
 * $ts = new TopicSubscription($pdo);
 *
 * $ts->subscribe('user-1', 'product.update');
 * $ts->subscribe('user-2', 'product.update');
 *
 * $subs  = $ts->subscribersOf('product.update');
 * $topics = $ts->topicsFor('user-1');
 * $ts->unsubscribe('user-1', 'product.update');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE topic_subscriptions (
 *     id           INTEGER PRIMARY KEY AUTOINCREMENT,
 *     user_id      VARCHAR(255) NOT NULL,
 *     topic        VARCHAR(200) NOT NULL,
 *     subscribed_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (user_id, topic)
 * );
 * ```
 */
final class TopicSubscription
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Subscribe a user to a topic (idempotent).
     *
     * @throws \InvalidArgumentException on empty user_id or topic.
     */
    public function subscribe(string $userId, string $topic): void
    {
        [$userId, $topic] = $this->validate($userId, $topic);
        $now    = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $driver = $this->db()->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $sql = 'INSERT OR IGNORE INTO topic_subscriptions (user_id, topic, subscribed_at)
                    VALUES (:uid, :topic, :now)';
        } else {
            $sql = 'INSERT IGNORE INTO topic_subscriptions (user_id, topic, subscribed_at)
                    VALUES (:uid, :topic, :now)';
        }
        $this->db()->prepare($sql)->execute([':uid' => $userId, ':topic' => $topic, ':now' => $now]);
    }

    /**
     * Unsubscribe a user from a topic.
     *
     * @return bool True if the subscription existed and was removed.
     */
    public function unsubscribe(string $userId, string $topic): bool
    {
        [$userId, $topic] = $this->validate($userId, $topic);
        $stmt = $this->db()->prepare(
            'DELETE FROM topic_subscriptions WHERE user_id = :uid AND topic = :topic'
        );
        $stmt->execute([':uid' => $userId, ':topic' => $topic]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Return true if the user is currently subscribed to the topic.
     */
    public function isSubscribed(string $userId, string $topic): bool
    {
        [$userId, $topic] = $this->validate($userId, $topic);
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM topic_subscriptions WHERE user_id = :uid AND topic = :topic'
        );
        $stmt->execute([':uid' => $userId, ':topic' => $topic]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Return all user IDs subscribed to a topic.
     *
     * @return list<string>
     */
    public function subscribersOf(string $topic): array
    {
        $stmt = $this->db()->prepare(
            'SELECT user_id FROM topic_subscriptions WHERE topic = :topic ORDER BY user_id ASC'
        );
        $stmt->execute([':topic' => trim($topic)]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    /**
     * Return the count of subscribers for a topic.
     */
    public function subscriberCount(string $topic): int
    {
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM topic_subscriptions WHERE topic = :topic'
        );
        $stmt->execute([':topic' => trim($topic)]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Return all topics a user is subscribed to, alphabetically.
     *
     * @return list<string>
     */
    public function topicsFor(string $userId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT topic FROM topic_subscriptions WHERE user_id = :uid ORDER BY topic ASC'
        );
        $stmt->execute([':uid' => trim($userId)]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    /**
     * Unsubscribe a user from all topics.
     *
     * @return int Number of subscriptions removed.
     */
    public function unsubscribeAll(string $userId): int
    {
        $stmt = $this->db()->prepare(
            'DELETE FROM topic_subscriptions WHERE user_id = :uid'
        );
        $stmt->execute([':uid' => trim($userId)]);
        return $stmt->rowCount();
    }

    /**
     * Remove all subscriptions for a topic.
     *
     * @return int Number of subscriptions removed.
     */
    public function removeTopic(string $topic): int
    {
        $stmt = $this->db()->prepare(
            'DELETE FROM topic_subscriptions WHERE topic = :topic'
        );
        $stmt->execute([':topic' => trim($topic)]);
        return $stmt->rowCount();
    }

    /**
     * Return distinct topic names, alphabetically.
     *
     * @return list<string>
     */
    public function allTopics(): array
    {
        $stmt = $this->db()->query(
            'SELECT DISTINCT topic FROM topic_subscriptions ORDER BY topic ASC'
        );
        if ($stmt === false) {
            return [];
        }
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    // ── internal helpers ──────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    /**
     * @return array{string, string}
     */
    private function validate(string $userId, string $topic): array
    {
        $userId = trim($userId);
        $topic  = trim($topic);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }
        if ($topic === '') {
            throw new \InvalidArgumentException('topic must not be empty.');
        }
        return [$userId, $topic];
    }
}
