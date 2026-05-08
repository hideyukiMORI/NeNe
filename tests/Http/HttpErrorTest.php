<?php

declare(strict_types=1);

namespace Nene\Tests\Http;

require_once __DIR__ . '/HttpRuntimeTestCase.php';

final class HttpErrorTest extends HttpRuntimeTestCase
{
    public function testLoginFailureReturnsCatalogError(): void
    {
        $response = $this->client->json('POST', '/session/login', [
            'user_id' => 'admin',
            'user_pass' => 'wrong',
        ]);
        $payload = $response->json();

        self::assertSame(401, $response->statusCode());
        self::assertSame('failure', $payload['Data']['status']);
        self::assertSame('LOGIN-FAILED', $payload['Data']['errorCode']);
    }

    public function testCreatingTodoWithEmptyTitleReturnsBadRequest(): void
    {
        $this->loginAsAdmin();

        $response = $this->client->json('POST', '/todo/index', ['title' => '']);
        $payload = $response->json();

        self::assertSame(400, $response->statusCode());
        self::assertSame('failure', $payload['Data']['status']);
        self::assertSame('TODO-TITLE-REQUIRED', $payload['Data']['errorCode']);
    }

    public function testStateChangingTodoRequestRequiresValidCsrfToken(): void
    {
        $this->loginAsAdmin();
        $this->client->setCsrfToken('invalid-token');

        $response = $this->client->json('POST', '/todo/index', [
            'title' => self::TEST_TODO_PREFIX . 'csrf',
        ]);
        $payload = $response->json();

        self::assertSame(403, $response->statusCode());
        self::assertSame('failure', $payload['Data']['status']);
        self::assertSame('CSRF-TOKEN-INVALID', $payload['Data']['errorCode']);
    }

    public function testLogoutRequiresValidCsrfToken(): void
    {
        $this->loginAsAdmin();
        $this->client->setCsrfToken('invalid-token');

        $response = $this->client->json('POST', '/session/logout');
        $payload = $response->json();

        self::assertSame(403, $response->statusCode());
        self::assertSame('failure', $payload['Data']['status']);
        self::assertSame('CSRF-TOKEN-INVALID', $payload['Data']['errorCode']);
    }

    public function testUpdatingMissingTodoReturnsNotFound(): void
    {
        $this->loginAsAdmin();

        $response = $this->client->json('PUT', '/todo/item/id_999999999', [
            'title' => self::TEST_TODO_PREFIX . 'missing',
        ]);
        $payload = $response->json();

        self::assertSame(404, $response->statusCode());
        self::assertSame('failure', $payload['Data']['status']);
        self::assertSame('TODO-NOT-FOUND', $payload['Data']['errorCode']);
    }

    public function testDeletingMissingTodoReturnsNotFound(): void
    {
        $this->loginAsAdmin();

        $response = $this->client->request('DELETE', '/todo/item/id_999999999');
        $payload = $response->json();

        self::assertSame(404, $response->statusCode());
        self::assertSame('failure', $payload['Data']['status']);
        self::assertSame('TODO-NOT-FOUND', $payload['Data']['errorCode']);
    }

    public function testInvalidTodoIdReturnsBadRequest(): void
    {
        $this->loginAsAdmin();

        $response = $this->client->json('PUT', '/todo/item/id_invalid', [
            'title' => self::TEST_TODO_PREFIX . 'invalid',
        ]);
        $payload = $response->json();

        self::assertSame(400, $response->statusCode());
        self::assertSame('failure', $payload['Data']['status']);
        self::assertSame('TODO-ID-REQUIRED', $payload['Data']['errorCode']);
    }
}
