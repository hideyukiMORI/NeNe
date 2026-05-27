<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\SurveyResponse;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SurveyResponse.
 */
final class SurveyResponseTest extends TestCase
{
    private PDO $db;
    private SurveyResponse $sr;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE survey_responses (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                survey_id    VARCHAR(255) NOT NULL,
                user_id      VARCHAR(255) NOT NULL,
                question_key VARCHAR(255) NOT NULL,
                answer       TEXT         NOT NULL DEFAULT \'\',
                created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (survey_id, user_id, question_key)
            )
        ');
        $this->sr = new SurveyResponse($this->db);
    }

    // ── submit ────────────────────────────────────────────────────────────────

    public function testSubmitStoresAnswers(): void
    {
        $this->sr->submit('s1', 'user-1', ['q1' => 'yes', 'q2' => 'no']);
        $answers = $this->sr->get('s1', 'user-1');
        $this->assertSame(['q1' => 'yes', 'q2' => 'no'], $answers);
    }

    public function testSubmitIsUpsert(): void
    {
        $this->sr->submit('s1', 'user-1', ['q1' => 'yes']);
        $this->sr->submit('s1', 'user-1', ['q1' => 'no']); // update
        $this->assertSame('no', $this->sr->getAnswer('s1', 'user-1', 'q1'));
    }

    public function testSubmitThrowsOnEmptySurveyId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sr->submit('', 'user-1', ['q1' => 'yes']);
    }

    public function testSubmitThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sr->submit('s1', '', ['q1' => 'yes']);
    }

    public function testSubmitThrowsOnEmptyAnswers(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sr->submit('s1', 'user-1', []);
    }

    // ── hasResponded ──────────────────────────────────────────────────────────

    public function testHasRespondedTrueAfterSubmit(): void
    {
        $this->sr->submit('s1', 'user-1', ['q1' => 'yes']);
        $this->assertTrue($this->sr->hasResponded('s1', 'user-1'));
    }

    public function testHasRespondedFalseBeforeSubmit(): void
    {
        $this->assertFalse($this->sr->hasResponded('s1', 'user-1'));
    }

    public function testHasRespondedIsUserScoped(): void
    {
        $this->sr->submit('s1', 'user-1', ['q1' => 'yes']);
        $this->assertFalse($this->sr->hasResponded('s1', 'user-2'));
    }

    // ── get ───────────────────────────────────────────────────────────────────

    public function testGetReturnsEmptyForNoResponse(): void
    {
        $this->assertSame([], $this->sr->get('s1', 'user-1'));
    }

    public function testGetIsUserScoped(): void
    {
        $this->sr->submit('s1', 'user-1', ['q1' => 'a']);
        $this->sr->submit('s1', 'user-2', ['q1' => 'b']);
        $this->assertSame(['q1' => 'a'], $this->sr->get('s1', 'user-1'));
    }

    // ── getAnswer ─────────────────────────────────────────────────────────────

    public function testGetAnswerReturnsAnswer(): void
    {
        $this->sr->submit('s1', 'user-1', ['q1' => 'yes']);
        $this->assertSame('yes', $this->sr->getAnswer('s1', 'user-1', 'q1'));
    }

    public function testGetAnswerReturnsNullForMissingQuestion(): void
    {
        $this->assertNull($this->sr->getAnswer('s1', 'user-1', 'missing'));
    }

    // ── respondentCount ───────────────────────────────────────────────────────

    public function testRespondentCountCountsDistinctUsers(): void
    {
        $this->sr->submit('s1', 'user-1', ['q1' => 'a']);
        $this->sr->submit('s1', 'user-1', ['q2' => 'b']); // same user
        $this->sr->submit('s1', 'user-2', ['q1' => 'c']);
        $this->assertSame(2, $this->sr->respondentCount('s1'));
    }

    public function testRespondentCountZeroForNoResponses(): void
    {
        $this->assertSame(0, $this->sr->respondentCount('s1'));
    }

    // ── tally ─────────────────────────────────────────────────────────────────

    public function testTallyCountsAnswers(): void
    {
        $this->sr->submit('s1', 'user-1', ['q1' => 'yes']);
        $this->sr->submit('s1', 'user-2', ['q1' => 'yes']);
        $this->sr->submit('s1', 'user-3', ['q1' => 'no']);
        $tally = $this->sr->tally('s1', 'q1');
        $this->assertSame(2, $tally['yes']);
        $this->assertSame(1, $tally['no']);
    }

    public function testTallyReturnsEmptyForNoAnswers(): void
    {
        $this->assertSame([], $this->sr->tally('s1', 'q1'));
    }

    // ── deleteUser ────────────────────────────────────────────────────────────

    public function testDeleteUserRemovesResponses(): void
    {
        $this->sr->submit('s1', 'user-1', ['q1' => 'yes']);
        $this->assertTrue($this->sr->deleteUser('s1', 'user-1'));
        $this->assertFalse($this->sr->hasResponded('s1', 'user-1'));
    }

    public function testDeleteUserReturnsFalseIfNoneFound(): void
    {
        $this->assertFalse($this->sr->deleteUser('s1', 'user-99'));
    }

    public function testDeleteUserDoesNotAffectOtherUsers(): void
    {
        $this->sr->submit('s1', 'user-1', ['q1' => 'yes']);
        $this->sr->submit('s1', 'user-2', ['q1' => 'no']);
        $this->sr->deleteUser('s1', 'user-1');
        $this->assertTrue($this->sr->hasResponded('s1', 'user-2'));
    }

    // ── deleteSurvey ──────────────────────────────────────────────────────────

    public function testDeleteSurveyRemovesAll(): void
    {
        $this->sr->submit('s1', 'user-1', ['q1' => 'yes']);
        $this->sr->submit('s1', 'user-2', ['q1' => 'no']);
        $this->assertSame(2, $this->sr->deleteSurvey('s1'));
        $this->assertSame(0, $this->sr->respondentCount('s1'));
    }

    public function testDeleteSurveyDoesNotAffectOtherSurveys(): void
    {
        $this->sr->submit('s1', 'user-1', ['q1' => 'yes']);
        $this->sr->submit('s2', 'user-1', ['q1' => 'no']);
        $this->sr->deleteSurvey('s1');
        $this->assertTrue($this->sr->hasResponded('s2', 'user-1'));
    }
}
