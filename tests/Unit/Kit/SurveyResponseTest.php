<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\SurveyResponse;
use PDO;
use PHPUnit\Framework\TestCase;

final class SurveyResponseTest extends TestCase
{
    private PDO $pdo;
    private SurveyResponse $sr;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE survey_responses (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                survey_name  VARCHAR(100) NOT NULL,
                respondent   VARCHAR(255) NULL,
                submitted_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->pdo->exec('
            CREATE TABLE survey_answers (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                response_id  INTEGER      NOT NULL,
                question_key VARCHAR(100) NOT NULL,
                answer       TEXT         NOT NULL
            )
        ');
        $this->sr = new SurveyResponse($this->pdo);
    }

    // ── submit ────────────────────────────────────────────────────────────────

    public function testSubmitReturnsId(): void
    {
        $id = $this->sr->submit('nps', 'user-1', ['score' => '9']);
        $this->assertGreaterThan(0, $id);
    }

    public function testSubmitStoresResponseHeader(): void
    {
        $id  = $this->sr->submit('nps', 'user-1', ['score' => '9']);
        $row = $this->sr->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('nps', $row['survey_name']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('user-1', $row['respondent']);
    }

    public function testSubmitStoresAnswers(): void
    {
        $id      = $this->sr->submit('nps', 'user-1', ['score' => '9', 'comment' => 'Great!']);
        $answers = $this->sr->answers($id);
        $this->assertSame('9', $answers['score']);
        $this->assertSame('Great!', $answers['comment']);
    }

    public function testSubmitAllowsNullRespondent(): void
    {
        $id  = $this->sr->submit('nps', null, ['score' => '7']);
        $row = $this->sr->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertNull($row['respondent']);
    }

    public function testSubmitThrowsOnEmptySurveyName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sr->submit('', 'user-1', ['score' => '5']);
    }

    public function testSubmitThrowsOnEmptyAnswers(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sr->submit('nps', 'user-1', []);
    }

    // ── find ──────────────────────────────────────────────────────────────────

    public function testFindReturnsNullForMissingId(): void
    {
        $this->assertNull($this->sr->find(9999));
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDeleteRemovesResponseAndAnswers(): void
    {
        $id = $this->sr->submit('nps', 'user-1', ['score' => '8', 'comment' => 'Good']);
        $this->assertTrue($this->sr->delete($id));
        $this->assertNull($this->sr->find($id));
        $this->assertSame([], $this->sr->answers($id));
    }

    public function testDeleteReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->sr->delete(9999));
    }

    // ── answers ───────────────────────────────────────────────────────────────

    public function testAnswersReturnsEmptyForMissingResponseId(): void
    {
        $this->assertSame([], $this->sr->answers(9999));
    }

    // ── forSurvey ─────────────────────────────────────────────────────────────

    public function testForSurveyReturnsNewestFirst(): void
    {
        $id1 = $this->sr->submit('nps', 'user-1', ['score' => '9']);
        $id2 = $this->sr->submit('nps', 'user-2', ['score' => '7']);
        $list = $this->sr->forSurvey('nps');
        $this->assertCount(2, $list);
        $this->assertSame($id2, (int)$list[0]['id']);
        $this->assertSame($id1, (int)$list[1]['id']);
    }

    public function testForSurveyIsIsolatedBySurvey(): void
    {
        $this->sr->submit('nps', 'user-1', ['score' => '9']);
        $this->sr->submit('csat', 'user-2', ['rating' => '5']);
        $this->assertCount(1, $this->sr->forSurvey('nps'));
        $this->assertCount(1, $this->sr->forSurvey('csat'));
    }

    public function testForSurveyReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->sr->forSurvey('unknown'));
    }

    public function testForSurveyRespectsLimitOffset(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->sr->submit('nps', 'user-' . $i, ['score' => (string)$i]);
        }
        $page1 = $this->sr->forSurvey('nps', 3, 0);
        $page2 = $this->sr->forSurvey('nps', 3, 3);
        $this->assertCount(3, $page1);
        $this->assertCount(2, $page2);
    }

    // ── countForSurvey ────────────────────────────────────────────────────────

    public function testCountForSurvey(): void
    {
        $this->sr->submit('nps', 'user-1', ['score' => '9']);
        $this->sr->submit('nps', 'user-2', ['score' => '7']);
        $this->sr->submit('csat', 'user-3', ['rating' => '5']);
        $this->assertSame(2, $this->sr->countForSurvey('nps'));
        $this->assertSame(1, $this->sr->countForSurvey('csat'));
    }

    // ── answerFrequency ───────────────────────────────────────────────────────

    public function testAnswerFrequencyReturnsCounts(): void
    {
        $this->sr->submit('nps', 'u1', ['score' => '9']);
        $this->sr->submit('nps', 'u2', ['score' => '9']);
        $this->sr->submit('nps', 'u3', ['score' => '7']);

        $freq = $this->sr->answerFrequency('nps', 'score');
        $this->assertSame(2, $freq['9']);
        $this->assertSame(1, $freq['7']);
    }

    public function testAnswerFrequencySortsByCountDesc(): void
    {
        $this->sr->submit('nps', 'u1', ['score' => '9']);
        $this->sr->submit('nps', 'u2', ['score' => '9']);
        $this->sr->submit('nps', 'u3', ['score' => '7']);

        $freq = $this->sr->answerFrequency('nps', 'score');
        $keys = array_keys($freq);
        // PHP converts numeric string keys to int; compare with ==
        $this->assertEquals('9', $keys[0]);
    }

    public function testAnswerFrequencyReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->sr->answerFrequency('nps', 'score'));
    }
}
