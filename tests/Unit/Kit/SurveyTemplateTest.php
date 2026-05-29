<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\SurveyTemplate;
use PDO;
use PHPUnit\Framework\TestCase;

final class SurveyTemplateTest extends TestCase
{
    private PDO $pdo;
    private SurveyTemplate $st;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE survey_templates (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                name        VARCHAR(255) NOT NULL,
                description TEXT         NULL,
                active      TINYINT(1)   NOT NULL DEFAULT 0,
                created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->pdo->exec('
            CREATE TABLE survey_template_questions (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                template_id  INTEGER      NOT NULL,
                question_key VARCHAR(100) NOT NULL,
                label        TEXT         NOT NULL,
                type         VARCHAR(50)  NOT NULL DEFAULT \'text\',
                position     INTEGER      NOT NULL DEFAULT 0,
                required     TINYINT(1)   NOT NULL DEFAULT 0
            )
        ');
        $this->st = new SurveyTemplate($this->pdo);
    }

    // ── create ────────────────────────────────────────────────────────────────

    public function testCreateReturnsId(): void
    {
        $id = $this->st->create('NPS Survey');
        $this->assertGreaterThan(0, $id);
    }

    public function testCreateStoresName(): void
    {
        $id  = $this->st->create('NPS Survey', 'Monthly survey');
        $row = $this->st->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('NPS Survey', $row['name']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('Monthly survey', $row['description']);
    }

    public function testCreateDefaultsToInactive(): void
    {
        $id  = $this->st->create('NPS Survey');
        $row = $this->st->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(0, (int)$row['active']);
    }

    public function testCreateThrowsOnEmptyName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->st->create('');
    }

    // ── get ───────────────────────────────────────────────────────────────────

    public function testGetReturnsNullForMissingId(): void
    {
        $this->assertNull($this->st->get(9999));
    }

    // ── listActive ────────────────────────────────────────────────────────────

    public function testListActiveReturnsOnlyActive(): void
    {
        $id1 = $this->st->create('Survey A');
        $id2 = $this->st->create('Survey B');
        $this->st->activate($id1);
        $active = $this->st->listActive();
        $this->assertCount(1, $active);
        $this->assertSame('Survey A', $active[0]['name']);
    }

    public function testListActiveReturnsEmptyWhenNone(): void
    {
        $this->st->create('Inactive Survey');
        $this->assertSame([], $this->st->listActive());
    }

    // ── activate / deactivate ─────────────────────────────────────────────────

    public function testActivateChangesFlag(): void
    {
        $id = $this->st->create('NPS Survey');
        $this->assertTrue($this->st->activate($id));
        $row = $this->st->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(1, (int)$row['active']);
    }

    public function testDeactivateChangesFlag(): void
    {
        $id = $this->st->create('NPS Survey');
        $this->st->activate($id);
        $this->assertTrue($this->st->deactivate($id));
        $row = $this->st->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(0, (int)$row['active']);
    }

    // ── addQuestion ───────────────────────────────────────────────────────────

    public function testAddQuestionReturnsId(): void
    {
        $id = $this->st->create('NPS Survey');
        $qid = $this->st->addQuestion($id, 'score', 'Rate us 0-10', SurveyTemplate::TYPE_NUMBER, 1, true);
        $this->assertGreaterThan(0, $qid);
    }

    public function testAddQuestionStoresFields(): void
    {
        $id  = $this->st->create('NPS Survey');
        $this->st->addQuestion($id, 'score', 'Rate us 0-10', SurveyTemplate::TYPE_NUMBER, 1, true);
        $questions = $this->st->questions($id);
        $this->assertCount(1, $questions);
        $this->assertSame('score', $questions[0]['question_key']);
        $this->assertSame('Rate us 0-10', $questions[0]['label']);
        $this->assertSame(SurveyTemplate::TYPE_NUMBER, $questions[0]['type']);
        $this->assertSame(1, (int)$questions[0]['required']);
    }

    public function testAddQuestionThrowsOnEmptyKey(): void
    {
        $id = $this->st->create('NPS Survey');
        $this->expectException(\InvalidArgumentException::class);
        $this->st->addQuestion($id, '', 'Rate us');
    }

    public function testAddQuestionThrowsOnEmptyLabel(): void
    {
        $id = $this->st->create('NPS Survey');
        $this->expectException(\InvalidArgumentException::class);
        $this->st->addQuestion($id, 'score', '');
    }

    // ── questions ─────────────────────────────────────────────────────────────

    public function testQuestionsAreOrderedByPosition(): void
    {
        $id = $this->st->create('NPS Survey');
        $this->st->addQuestion($id, 'comment', 'Any comments?', SurveyTemplate::TYPE_TEXTAREA, 2);
        $this->st->addQuestion($id, 'score', 'Rate us 0-10', SurveyTemplate::TYPE_NUMBER, 1);
        $questions = $this->st->questions($id);
        $this->assertSame('score', $questions[0]['question_key']);
        $this->assertSame('comment', $questions[1]['question_key']);
    }

    public function testQuestionsReturnsEmptyWhenNone(): void
    {
        $id = $this->st->create('Empty Survey');
        $this->assertSame([], $this->st->questions($id));
    }

    // ── removeQuestion ────────────────────────────────────────────────────────

    public function testRemoveQuestionDeletesQuestion(): void
    {
        $id  = $this->st->create('NPS Survey');
        $qid = $this->st->addQuestion($id, 'score', 'Rate us');
        $this->assertTrue($this->st->removeQuestion($qid));
        $this->assertCount(0, $this->st->questions($id));
    }

    public function testRemoveQuestionReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->st->removeQuestion(9999));
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDeleteRemovesTemplateAndQuestions(): void
    {
        $id = $this->st->create('NPS Survey');
        $this->st->addQuestion($id, 'score', 'Rate us');
        $this->assertTrue($this->st->delete($id));
        $this->assertNull($this->st->get($id));
        $this->assertSame([], $this->st->questions($id));
    }

    public function testDeleteReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->st->delete(9999));
    }
}
