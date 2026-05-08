<?php

declare(strict_types=1);

namespace Nene\Tests\Http;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/HttpClient.php';

abstract class HttpRuntimeTestCase extends TestCase
{
    protected const BASE_URL_ENV = 'NENE_HTTP_BASE_URL';
    protected const TEST_TODO_PREFIX = 'HTTP runtime test ';

    /**
     * Runtime test client.
     *
     * @var HttpClient
     */
    protected $client;

    /**
     * TODO ids created by a test.
     *
     * @var array<int,int>
     */
    private $cleanupTodoIds = [];

    protected function setUp(): void
    {
        $baseUrl = $this->baseUrl();
        $this->client = new HttpClient($baseUrl);
        try {
            $this->client->request('GET', '/api-docs/openapi.php');
        } catch (\RuntimeException $exception) {
            self::markTestSkipped('HTTP runtime is not reachable: ' . $exception->getMessage());
        }

        $this->deleteTodosWithTitlePrefix(self::TEST_TODO_PREFIX);
    }

    protected function tearDown(): void
    {
        if ($this->cleanupTodoIds !== []) {
            $cleanupClient = $this->newClient();
            $this->loginAsAdmin($cleanupClient);
            foreach (array_unique($this->cleanupTodoIds) as $todoId) {
                $cleanupClient->request('DELETE', '/todo/item/id_' . $todoId);
            }
        }
        $this->cleanupTodoIds = [];
    }

    protected function newClient(): HttpClient
    {
        return new HttpClient($this->baseUrl());
    }

    protected function loginAsAdmin(?HttpClient $client = null): HttpClient
    {
        $client = $client ?? $this->client;
        $response = $client->json('POST', '/session/login', [
            'user_id' => 'admin',
            'user_pass' => 'admin',
        ]);
        $payload = $response->json();

        self::assertSame(200, $response->statusCode());
        self::assertSame('success', $payload['Data']['status']);
        self::assertNotSame('', $payload['Data']['csrfToken'] ?? '');
        return $client;
    }

    /**
     * @return array<string,mixed> Created TODO payload.
     */
    protected function createTodo(string $title, ?HttpClient $client = null): array
    {
        $client = $client ?? $this->client;
        $response = $client->json('POST', '/todo/index', ['title' => $title]);
        $payload = $response->json();

        self::assertSame(200, $response->statusCode());
        self::assertSame('success', $payload['Data']['status']);

        $todo = $payload['Data']['todo'];
        $this->cleanupTodoIds[] = (int)$todo['id'];
        return $todo;
    }

    private function baseUrl(): string
    {
        $baseUrl = (string)getenv(self::BASE_URL_ENV);
        if ($baseUrl === '') {
            self::markTestSkipped(self::BASE_URL_ENV . ' is not configured.');
        }
        return $baseUrl;
    }

    private function deleteTodosWithTitlePrefix(string $titlePrefix): void
    {
        $cleanupClient = $this->newClient();
        $this->loginAsAdmin($cleanupClient);

        $listResponse = $cleanupClient->request('GET', '/todo/index');
        $listPayload = $listResponse->json();
        foreach ($listPayload['Data']['todos'] ?? [] as $todo) {
            if (str_starts_with((string)$todo['title'], $titlePrefix)) {
                $cleanupClient->request('DELETE', '/todo/item/id_' . (int)$todo['id']);
            }
        }
    }
}
