<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\TopicSubscription;
use PDO;
use PHPUnit\Framework\TestCase;

final class TopicSubscriptionTest extends TestCase
{
    private PDO $pdo;
    private TopicSubscription $ts;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE topic_subscriptions (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id       VARCHAR(255) NOT NULL,
                topic         VARCHAR(200) NOT NULL,
                subscribed_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (user_id, topic)
            )
        ');
        $this->ts = new TopicSubscription($this->pdo);
    }

    // ── subscribe / isSubscribed ──────────────────────────────────────────────

    public function testSubscribeAndIsSubscribed(): void
    {
        $this->ts->subscribe('u1', 'product.update');
        $this->assertTrue($this->ts->isSubscribed('u1', 'product.update'));
    }

    public function testSubscribeIsIdempotent(): void
    {
        $this->ts->subscribe('u1', 'topic');
        $this->ts->subscribe('u1', 'topic');
        $this->assertSame(1, $this->ts->subscriberCount('topic'));
    }

    public function testIsSubscribedReturnsFalseWhenNot(): void
    {
        $this->assertFalse($this->ts->isSubscribed('u1', 'topic'));
    }

    public function testSubscribeThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ts->subscribe('', 'topic');
    }

    public function testSubscribeThrowsOnEmptyTopic(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ts->subscribe('u1', '');
    }

    // ── unsubscribe ───────────────────────────────────────────────────────────

    public function testUnsubscribeRemovesSubscription(): void
    {
        $this->ts->subscribe('u1', 'topic');
        $this->assertTrue($this->ts->unsubscribe('u1', 'topic'));
        $this->assertFalse($this->ts->isSubscribed('u1', 'topic'));
    }

    public function testUnsubscribeReturnsFalseWhenNotSubscribed(): void
    {
        $this->assertFalse($this->ts->unsubscribe('u1', 'topic'));
    }

    // ── subscribersOf ─────────────────────────────────────────────────────────

    public function testSubscribersOfReturnsAllSubscribers(): void
    {
        $this->ts->subscribe('u1', 'news');
        $this->ts->subscribe('u2', 'news');
        $this->ts->subscribe('u3', 'other');

        $subs = $this->ts->subscribersOf('news');
        $this->assertCount(2, $subs);
        $this->assertContains('u1', $subs);
        $this->assertContains('u2', $subs);
    }

    public function testSubscribersOfReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->ts->subscribersOf('unknown'));
    }

    // ── subscriberCount ───────────────────────────────────────────────────────

    public function testSubscriberCountIsCorrect(): void
    {
        $this->ts->subscribe('u1', 't');
        $this->ts->subscribe('u2', 't');
        $this->assertSame(2, $this->ts->subscriberCount('t'));
    }

    public function testSubscriberCountZeroWhenNone(): void
    {
        $this->assertSame(0, $this->ts->subscriberCount('empty'));
    }

    // ── topicsFor ─────────────────────────────────────────────────────────────

    public function testTopicsForReturnsAlphabeticList(): void
    {
        $this->ts->subscribe('u1', 'z-topic');
        $this->ts->subscribe('u1', 'a-topic');
        $this->ts->subscribe('u1', 'm-topic');

        $topics = $this->ts->topicsFor('u1');
        $this->assertSame(['a-topic', 'm-topic', 'z-topic'], $topics);
    }

    public function testTopicsForReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->ts->topicsFor('u99'));
    }

    // ── unsubscribeAll ────────────────────────────────────────────────────────

    public function testUnsubscribeAllRemovesAllUserSubscriptions(): void
    {
        $this->ts->subscribe('u1', 'a');
        $this->ts->subscribe('u1', 'b');
        $this->ts->subscribe('u2', 'a');

        $count = $this->ts->unsubscribeAll('u1');
        $this->assertSame(2, $count);
        $this->assertSame([], $this->ts->topicsFor('u1'));
        $this->assertCount(1, $this->ts->subscribersOf('a')); // u2 still subscribed
    }

    // ── removeTopic ───────────────────────────────────────────────────────────

    public function testRemoveTopicDeletesAllSubscriptions(): void
    {
        $this->ts->subscribe('u1', 'old');
        $this->ts->subscribe('u2', 'old');
        $this->ts->subscribe('u1', 'keep');

        $count = $this->ts->removeTopic('old');
        $this->assertSame(2, $count);
        $this->assertSame([], $this->ts->subscribersOf('old'));
        $this->assertCount(1, $this->ts->subscribersOf('keep'));
    }

    // ── allTopics ─────────────────────────────────────────────────────────────

    public function testAllTopicsReturnsDistinctSortedList(): void
    {
        $this->ts->subscribe('u1', 'b');
        $this->ts->subscribe('u2', 'a');
        $this->ts->subscribe('u1', 'a');

        $topics = $this->ts->allTopics();
        $this->assertSame(['a', 'b'], $topics);
    }

    public function testAllTopicsReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->ts->allTopics());
    }
}
