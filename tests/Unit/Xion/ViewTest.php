<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\View;
use PHPUnit\Framework\TestCase;

final class ViewTest extends TestCase
{
    public function testEncodeScriptJsonEscapesScriptBreakingCharacters(): void
    {
        $json = View::encodeScriptJson([
            'html' => '</script><script>alert("xss")</script>',
            'quote' => "'\"&<>",
        ]);

        self::assertStringNotContainsString('</script>', $json);
        self::assertStringNotContainsString('<script>', $json);
        self::assertStringContainsString('\u003C\/script\u003E', $json);
        self::assertStringContainsString('\u0022', $json);
        self::assertStringContainsString('\u0027', $json);
        self::assertStringContainsString('\u0026', $json);
    }
}
