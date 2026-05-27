<?php

declare(strict_types=1);

namespace Tests\Unit\Xion;

use Nene\Xion\TextTemplate;
use PDO;
use PHPUnit\Framework\TestCase;

final class TextTemplateTest extends TestCase
{
    private PDO $pdo;
    private TextTemplate $tt;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE text_templates (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                tpl_key     VARCHAR(100) NOT NULL,
                channel     VARCHAR(50)  NOT NULL,
                locale      CHAR(5)      NOT NULL DEFAULT \'en\',
                body        TEXT         NOT NULL,
                active      TINYINT(1)   NOT NULL DEFAULT 1,
                created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (tpl_key, channel, locale)
            )
        ');
        $this->tt = new TextTemplate($this->pdo);
    }

    // ── set ───────────────────────────────────────────────────────────────────

    public function testSetCreatesTemplate(): void
    {
        $this->tt->set('welcome', 'sms', 'Hi {{name}}!');
        $row = $this->tt->get('welcome', 'sms');
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('Hi {{name}}!', $row['body']);
    }

    public function testSetUpdatesExistingTemplate(): void
    {
        $this->tt->set('welcome', 'sms', 'Hi {{name}}!');
        $this->tt->set('welcome', 'sms', 'Hello {{name}}!');
        $row = $this->tt->get('welcome', 'sms');
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('Hello {{name}}!', $row['body']);
    }

    public function testSetThrowsOnEmptyKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->tt->set('', 'sms', 'body');
    }

    public function testSetThrowsOnEmptyChannel(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->tt->set('welcome', '', 'body');
    }

    public function testSetThrowsOnEmptyBody(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->tt->set('welcome', 'sms', '');
    }

    // ── get ───────────────────────────────────────────────────────────────────

    public function testGetReturnsNullWhenNotFound(): void
    {
        $this->assertNull($this->tt->get('nonexistent', 'sms'));
    }

    public function testGetFallsBackToEnLocale(): void
    {
        $this->tt->set('welcome', 'sms', 'Hi {{name}}!', 'en');
        $row = $this->tt->get('welcome', 'sms', 'fr'); // no French template
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('en', $row['locale']);
    }

    public function testGetReturnsLocaleSpecificWhenAvailable(): void
    {
        $this->tt->set('welcome', 'sms', 'Hi {{name}}!', 'en');
        $this->tt->set('welcome', 'sms', 'こんにちは {{name}}！', 'ja');
        $row = $this->tt->get('welcome', 'sms', 'ja');
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('ja', $row['locale']);
    }

    // ── render ────────────────────────────────────────────────────────────────

    public function testRenderSubstitutesVariables(): void
    {
        $this->tt->set('otp', 'sms', 'Your code is {{code}}. Expires in {{minutes}} min.');
        $text = $this->tt->render('otp', 'sms', ['code' => '1234', 'minutes' => 5]);
        $this->assertSame('Your code is 1234. Expires in 5 min.', $text);
    }

    public function testRenderWithNoVars(): void
    {
        $this->tt->set('static', 'push', 'You have a new message.');
        $this->assertSame('You have a new message.', $this->tt->render('static', 'push'));
    }

    public function testRenderThrowsWhenTemplateNotFound(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->tt->render('missing', 'sms', []);
    }

    public function testRenderUsesLocaleFallback(): void
    {
        $this->tt->set('welcome', 'sms', 'Hi {{name}}!', 'en');
        $text = $this->tt->render('welcome', 'sms', ['name' => 'Bob'], 'de');
        $this->assertSame('Hi Bob!', $text);
    }

    // ── activate / deactivate ─────────────────────────────────────────────────

    public function testDeactivateHidesFromGet(): void
    {
        $this->tt->set('welcome', 'sms', 'Hi {{name}}!');
        $this->tt->deactivate('welcome', 'sms');
        $this->assertNull($this->tt->get('welcome', 'sms'));
    }

    public function testActivateRestoresTemplate(): void
    {
        $this->tt->set('welcome', 'sms', 'Hi {{name}}!');
        $this->tt->deactivate('welcome', 'sms');
        $this->tt->activate('welcome', 'sms');
        $this->assertNotNull($this->tt->get('welcome', 'sms'));
    }

    // ── remove ────────────────────────────────────────────────────────────────

    public function testRemoveSpecificLocale(): void
    {
        $this->tt->set('welcome', 'sms', 'Hi!', 'en');
        $this->tt->set('welcome', 'sms', 'こんにちは！', 'ja');
        $deleted = $this->tt->remove('welcome', 'sms', 'ja');
        $this->assertSame(1, $deleted);
        $this->assertNotNull($this->tt->get('welcome', 'sms', 'en'));
    }

    public function testRemoveAllLocales(): void
    {
        $this->tt->set('welcome', 'sms', 'Hi!', 'en');
        $this->tt->set('welcome', 'sms', 'こんにちは！', 'ja');
        $deleted = $this->tt->remove('welcome', 'sms');
        $this->assertSame(2, $deleted);
        $this->assertNull($this->tt->get('welcome', 'sms'));
    }
}
