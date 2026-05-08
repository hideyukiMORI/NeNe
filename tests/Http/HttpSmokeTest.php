<?php

declare(strict_types=1);

namespace Nene\Tests\Http;

require_once __DIR__ . '/HttpRuntimeTestCase.php';

final class HttpSmokeTest extends HttpRuntimeTestCase
{
    public function testTopPageRespondsWithDevelopersHtml(): void
    {
        $response = $this->client->request('GET', '/');

        self::assertSame(200, $response->statusCode());
        self::assertStringContainsString('text/html', $response->headerLine('Content-Type'));
        self::assertStringContainsString('id="app"', $response->body());
        self::assertStringContainsString('/js/index/index.js', $response->body());
        self::assertStringContainsString('/css/index/index.css', $response->body());
    }

    public function testExplicitIndexRouteRespondsWithDevelopersHtml(): void
    {
        $response = $this->client->request('GET', '/index/index');

        self::assertSame(200, $response->statusCode());
        self::assertStringContainsString('text/html', $response->headerLine('Content-Type'));
        self::assertStringContainsString('id="app"', $response->body());
        self::assertStringContainsString('/js/index/index.js', $response->body());
    }

    public function testTopPageJavaScriptIncludesServiceTutorialGuide(): void
    {
        $response = $this->client->request('GET', '/js/index/index.js');

        self::assertSame(200, $response->statusCode());
        self::assertStringContainsString('Tutorial', $response->body());
        self::assertStringContainsString('Build a small service', $response->body());
        self::assertStringContainsString('docs/tutorials/building-a-service.md', $response->body());
        self::assertStringContainsString('/serverinstall/index', $response->body());
        self::assertStringContainsString('/health/index', $response->body());
        self::assertStringContainsString('php cli/setupDatabase.php --env=.env --yes', $response->body());
        self::assertStringContainsString('DB Type: ', $response->body());
    }

    public function testHealthEndpointReportsApiDatabaseAndSchemaStatus(): void
    {
        $response = $this->client->request('GET', '/health/index');
        $payload = $response->json();

        self::assertSame(200, $response->statusCode());
        self::assertSame(true, $payload['Result']);
        self::assertSame('success', $payload['Data']['status']);
        self::assertSame('ok', $payload['Data']['healthStatus']);
        self::assertSame(true, $payload['Data']['api']);
        self::assertSame(true, $payload['Data']['database']);
        self::assertSame(true, $payload['Data']['schema']);
        self::assertSame('development', $payload['Data']['environment']);
        self::assertSame('MySQL', $payload['Data']['databaseType']);
    }

    public function testServerInstallGuidePageRespondsWithDeploymentChecklist(): void
    {
        $response = $this->client->request('GET', '/serverinstall/index');

        self::assertSame(200, $response->statusCode());
        self::assertStringContainsString('text/html', $response->headerLine('Content-Type'));
        self::assertStringContainsString('Install NeNe after git clone', $response->body());
        self::assertStringContainsString('composer install --no-dev --optimize-autoloader', $response->body());
        self::assertStringContainsString('php cli/setupDatabase.php --env=.env --yes', $response->body());
        self::assertStringContainsString('NENE_APP_ENV=production', $response->body());
        self::assertStringContainsString('nene-php.com', $response->body());
    }

    public function testSwaggerUiAndOpenApiDocumentAreServed(): void
    {
        $swaggerResponse = $this->client->request('GET', '/api-docs/');
        $openApiResponse = $this->client->request('GET', '/api-docs/openapi.php');

        self::assertSame(200, $swaggerResponse->statusCode());
        self::assertStringContainsString('SwaggerUIBundle', $swaggerResponse->body());

        self::assertSame(200, $openApiResponse->statusCode());
        self::assertStringContainsString('application/yaml', $openApiResponse->headerLine('Content-Type'));
        self::assertStringContainsString('openapi: 3.1.0', $openApiResponse->body());
    }

    public function testTodoListRequiresSession(): void
    {
        $response = $this->client->request('GET', '/todo/index');
        $payload = $response->json();

        self::assertSame(401, $response->statusCode());
        self::assertSame(true, $payload['Result']);
        self::assertSame('failure', $payload['Data']['status']);
        self::assertSame('SESSION-CLOSED', $payload['Data']['errorCode']);
    }

    public function testSessionCookieHasExplicitSecurityAttributes(): void
    {
        $client = $this->newClient();
        $loginResponse = $client->json('POST', '/session/login', [
            'user_id' => 'admin',
            'user_pass' => 'admin',
        ]);
        $loginCookie = $loginResponse->headerLine('Set-Cookie');

        self::assertSame(200, $loginResponse->statusCode());
        self::assertStringContainsString('PHPSESSID=', $loginCookie);
        self::assertStringContainsString('HttpOnly', $loginCookie);
        self::assertStringContainsString('SameSite=Lax', $loginCookie);

        $logoutResponse = $client->json('POST', '/session/logout');
        $logoutCookie = $logoutResponse->headerLine('Set-Cookie');

        self::assertSame(200, $logoutResponse->statusCode());
        self::assertStringContainsString('PHPSESSID=', $logoutCookie);
        self::assertStringContainsString('expires=', strtolower($logoutCookie));
    }

    public function testLoginRestEndpointRejectsUnsupportedMethod(): void
    {
        $response = $this->client->request('GET', '/session/login');
        $payload = $response->json();

        self::assertSame(405, $response->statusCode());
        self::assertSame('POST', $response->headerLine('Allow'));
        self::assertSame(true, $payload['Result']);
        self::assertSame('failure', $payload['Data']['status']);
        self::assertSame('METHOD-NOT-ALLOWED', $payload['Data']['errorCode']);
    }

    public function testRestResponseStaysJsonWhenCallbackQueryIsPresent(): void
    {
        $response = $this->client->json('POST', '/session/login?callback=jsonCallback', [
            'user_id' => 'admin',
            'user_pass' => 'admin',
        ]);
        $payload = $response->json();

        self::assertSame(200, $response->statusCode());
        self::assertStringContainsString('application/json', $response->headerLine('Content-Type'));
        self::assertStringNotContainsString('jsonCallback(', $response->body());
        self::assertSame(true, $payload['Result']);
        self::assertSame('success', $payload['Data']['status']);
    }

    public function testSessionAndTodoCrudFlowRunsThroughHttpRuntime(): void
    {
        $this->loginAsAdmin();

        $title = self::TEST_TODO_PREFIX . bin2hex(random_bytes(4));
        $createdTodo = $this->createTodo($title);
        self::assertSame($title, $createdTodo['title']);

        $listResponse = $this->client->request('GET', '/todo/index');
        $listPayload = $listResponse->json();
        $todoIds = array_column($listPayload['Data']['todos'], 'id');

        self::assertSame(200, $listResponse->statusCode());
        self::assertContains($createdTodo['id'], $todoIds);

        $updatedTitle = $title . ' updated';
        $updateResponse = $this->client->json('PUT', '/todo/item/id_' . $createdTodo['id'], [
            'title' => $updatedTitle,
            'is_completed' => true,
        ]);
        $updatePayload = $updateResponse->json();

        self::assertSame(200, $updateResponse->statusCode());
        self::assertSame($updatedTitle, $updatePayload['Data']['todo']['title']);
        self::assertSame(true, $updatePayload['Data']['todo']['is_completed']);

        $deleteResponse = $this->client->request('DELETE', '/todo/item/id_' . $createdTodo['id']);
        $deletePayload = $deleteResponse->json();

        self::assertSame(200, $deleteResponse->statusCode());
        self::assertSame($createdTodo['id'], $deletePayload['Data']['id']);

        $logoutResponse = $this->client->json('POST', '/session/logout');
        $logoutPayload = $logoutResponse->json();

        self::assertSame(200, $logoutResponse->statusCode());
        self::assertSame('success', $logoutPayload['Data']['status']);
    }

    public function testUnsupportedRestMethodReturnsMethodNotAllowed(): void
    {
        $response = $this->client->request('GET', '/todo/item/id_1');
        $payload = $response->json();

        self::assertSame(405, $response->statusCode());
        self::assertStringContainsString('DELETE', $response->headerLine('Allow'));
        self::assertStringContainsString('PUT', $response->headerLine('Allow'));
        self::assertSame(true, $payload['Result']);
        self::assertSame('failure', $payload['Data']['status']);
        self::assertSame('METHOD-NOT-ALLOWED', $payload['Data']['errorCode']);
    }
}
