<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\AuthSession;
use PHPUnit\Framework\TestCase;

final class AuthSessionTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testLoginStoresNormalizedUserInformation(): void
    {
        $authSession = AuthSession::getInstance();

        $user = $authSession->login([
            'id' => '1',
            'user_id' => 'admin',
            'user_name' => 'Administrator',
            'e_mail' => 'admin@example.com',
        ]);

        self::assertTrue($authSession->isLoggedIn());
        self::assertSame(1, $authSession->userId());
        self::assertSame('admin', $authSession->userIdentifier());
        self::assertSame($user, $authSession->user());
    }

    public function testLogoutClearsAuthenticationSession(): void
    {
        $authSession = AuthSession::getInstance();
        $authSession->login([
            'id' => 1,
            'user_id' => 'admin',
            'user_name' => 'Administrator',
            'e_mail' => 'admin@example.com',
        ]);

        $authSession->logout();

        self::assertFalse($authSession->isLoggedIn());
        self::assertNull($authSession->user());
        self::assertNull($authSession->userId());
        self::assertSame('', $authSession->userIdentifier());
    }

    public function testLogoutCanDestroyWholeSessionState(): void
    {
        $_SESSION['global']['referer']['controller'] = 'index';

        $authSession = AuthSession::getInstance();
        $authSession->login([
            'id' => 1,
            'user_id' => 'admin',
            'user_name' => 'Administrator',
            'e_mail' => 'admin@example.com',
        ]);

        $authSession->logout(true);

        self::assertSame([], $_SESSION);
    }

    public function testLoginModeWithoutUserIsNotLoggedIn(): void
    {
        $_SESSION['xion']['login_mode'] = 'login';

        self::assertFalse(AuthSession::getInstance()->isLoggedIn());
    }
}
