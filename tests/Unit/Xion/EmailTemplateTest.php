<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\EmailTemplate;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for EmailTemplate.
 */
final class EmailTemplateTest extends TestCase
{
    private PDO $db;
    private EmailTemplate $et;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE email_templates (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                name       VARCHAR(100) NOT NULL,
                locale     VARCHAR(10)  NOT NULL DEFAULT \'en\',
                subject    TEXT         NOT NULL DEFAULT \'\',
                body       TEXT         NOT NULL DEFAULT \'\',
                updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (name, locale)
            )
        ');
        $this->et = new EmailTemplate($this->db);
    }

    // ── save / find ───────────────────────────────────────────────────────────

    public function testSaveAndFind(): void
    {
        $this->et->save('welcome', 'Welcome!', 'Hello there.');
        $row = $this->et->find('welcome');
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('Welcome!', $row['subject']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('Hello there.', $row['body']);
    }

    public function testFindReturnsNullForMissing(): void
    {
        $this->assertNull($this->et->find('nonexistent'));
    }

    public function testSaveIsUpsert(): void
    {
        $this->et->save('welcome', 'Old subject', 'Old body');
        $this->et->save('welcome', 'New subject', 'New body');
        $row = $this->et->find('welcome');
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('New subject', $row['subject']);
    }

    public function testSaveThrowsOnEmptyName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->et->save('', 'Subject', 'Body');
    }

    // ── locale support ────────────────────────────────────────────────────────

    public function testSaveAndFindDifferentLocales(): void
    {
        $this->et->save('welcome', 'Welcome!', 'Hi there.', 'en');
        $this->et->save('welcome', 'Bienvenue !', 'Bonjour.', 'fr');

        $en = $this->et->find('welcome', 'en');
        $fr = $this->et->find('welcome', 'fr');

        $this->assertNotNull($en);
        $this->assertNotNull($fr);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('Welcome!', $en['subject']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('Bienvenue !', $fr['subject']);
    }

    public function testFindReturnsNullForMissingLocale(): void
    {
        $this->et->save('welcome', 'Welcome!', 'Body', 'en');
        $this->assertNull($this->et->find('welcome', 'de'));
    }

    // ── render ────────────────────────────────────────────────────────────────

    public function testRenderSubstitutesPlaceholders(): void
    {
        $this->et->save('greet', 'Hi {{name}}!', 'Hello {{name}}, your code is {{code}}.');
        [$subject, $body] = $this->et->render('greet', ['name' => 'Alice', 'code' => '1234']);
        $this->assertSame('Hi Alice!', $subject);
        $this->assertSame('Hello Alice, your code is 1234.', $body);
    }

    public function testRenderLeavesUnknownPlaceholdersIntact(): void
    {
        $this->et->save('greet', 'Hi {{name}}!', 'Body {{unknown}}');
        [$subject, $body] = $this->et->render('greet', ['name' => 'Bob']);
        $this->assertSame('Hi Bob!', $subject);
        $this->assertStringContainsString('{{unknown}}', $body);
    }

    public function testRenderFallsBackToEnLocale(): void
    {
        $this->et->save('alert', 'Alert!', 'Check this.', 'en');
        [$subject] = $this->et->render('alert', [], 'fr');
        $this->assertSame('Alert!', $subject);
    }

    public function testRenderUsesRequestedLocale(): void
    {
        $this->et->save('alert', 'Alert!', 'Check.', 'en');
        $this->et->save('alert', 'Alerte !', 'Vérifiez.', 'fr');
        [$subject] = $this->et->render('alert', [], 'fr');
        $this->assertSame('Alerte !', $subject);
    }

    public function testRenderThrowsWhenTemplateNotFound(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->et->render('missing', []);
    }

    // ── all ───────────────────────────────────────────────────────────────────

    public function testAllReturnsAllTemplates(): void
    {
        $this->et->save('a', 'Subject A', 'Body A');
        $this->et->save('b', 'Subject B', 'Body B');
        $this->et->save('a', 'Sujet A', 'Corps A', 'fr');
        $rows = $this->et->all();
        $this->assertCount(3, $rows);
    }

    public function testAllReturnsEmptyWhenNoTemplates(): void
    {
        $this->assertSame([], $this->et->all());
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDeleteSpecificLocale(): void
    {
        $this->et->save('welcome', 'Welcome!', 'Body', 'en');
        $this->et->save('welcome', 'Bienvenue !', 'Corps', 'fr');
        $deleted = $this->et->delete('welcome', 'fr');
        $this->assertSame(1, $deleted);
        $this->assertNotNull($this->et->find('welcome', 'en'));
        $this->assertNull($this->et->find('welcome', 'fr'));
    }

    public function testDeleteAllLocales(): void
    {
        $this->et->save('welcome', 'Welcome!', 'Body', 'en');
        $this->et->save('welcome', 'Bienvenue !', 'Corps', 'fr');
        $deleted = $this->et->delete('welcome');
        $this->assertSame(2, $deleted);
    }

    // ── variables ─────────────────────────────────────────────────────────────

    public function testVariablesExtractsPlaceholderNames(): void
    {
        $this->et->save('reset', 'Reset {{name}}', 'Click {{link}} to reset {{name}}.');
        $vars = $this->et->variables('reset');
        sort($vars);
        $this->assertSame(['link', 'name'], $vars);
    }

    public function testVariablesReturnsEmptyForMissingTemplate(): void
    {
        $this->assertSame([], $this->et->variables('missing'));
    }
}
