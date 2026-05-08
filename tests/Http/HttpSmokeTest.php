<?php

declare(strict_types=1);

namespace Nene\Tests\Http;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/HttpClient.php';

final class HttpSmokeTest extends TestCase
{
    private const BASE_URL_ENV = 'NENE_HTTP_BASE_URL';

    /**
     * Runtime test client.
     *
     * @var HttpClient
     */
    private $client;

    protected function setUp(): void
    {
        $baseUrl = (string)getenv(self::BASE_URL_ENV);
        if ($baseUrl === '') {
            self::markTestSkipped(self::BASE_URL_ENV . ' is not configured.');
        }

        $this->client = new HttpClient($baseUrl);
        try {
            $this->client->request('GET', '/api-docs/openapi.php');
        } catch (\RuntimeException $exception) {
            self::markTestSkipped('HTTP runtime is not reachable: ' . $exception->getMessage());
        }
    }

    public function testTopPageRespondsWithDevelopersHtml(): void
    {
        $response = $this->client->request('GET', '/');

        self::assertSame(200, $response->statusCode());
        self::assertStringContainsString('text/html', $response->headerLine('Content-Type'));
        self::assertStringContainsString('id="app"', $response->body());
        self::assertStringContainsString('/js/index/index.js', $response->body());
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

        self::assertSame(200, $response->statusCode());
        self::assertSame(true, $payload['Result']);
        self::assertSame('failure', $payload['Data']['status']);
        self::assertSame('SESSION-CLOSED', $payload['Data']['errorCode']);
    }

    public function testSessionAndTodoCrudFlowRunsThroughHttpRuntime(): void
    {
        $loginResponse = $this->client->json('POST', '/session/login', [
            'user_id' => 'admin',
            'user_pass' => 'admin'
        ]);
        $loginPayload = $loginResponse->json();

        self::assertSame(200, $loginResponse->statusCode());
        self::assertSame(true, $loginPayload['Result']);
        self::assertSame('success', $loginPayload['Data']['status']);
        self::assertSame('admin', $loginPayload['Data']['user']['user_id']);

        $title = 'HTTP runtime test ' . bin2hex(random_bytes(4));
        $createResponse = $this->client->json('POST', '/todo/index', ['title' => $title]);
        $createPayload = $createResponse->json();
        $createdTodo = $createPayload['Data']['todo'];

        self::assertSame(200, $createResponse->statusCode());
        self::assertSame('success', $createPayload['Data']['status']);
        self::assertSame($title, $createdTodo['title']);

        $listResponse = $this->client->request('GET', '/todo/index');
        $listPayload = $listResponse->json();
        $todoIds = array_column($listPayload['Data']['todos'], 'id');

        self::assertSame(200, $listResponse->statusCode());
        self::assertContains($createdTodo['id'], $todoIds);

        $updatedTitle = $title . ' updated';
        $updateResponse = $this->client->json('PUT', '/todo/item/id_' . $createdTodo['id'], [
            'title' => $updatedTitle,
            'is_completed' => true
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
        self::assertSame(false, $payload['Result']);
        self::assertSame('METHOD-NOT-ALLOWED', $payload['Error']['ErrorCode']);
    }
}
