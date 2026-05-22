<?php

declare(strict_types=1);

namespace Nene\Tests\Http;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/HttpClient.php';

/**
 * End-to-end HTTP smoke for the FT16 / ADR-0008 Bearer auth path,
 * closing the final unchecked box on Issue #395 ("PHPUnit/integration:
 * list + create with Bearer only (no cookie)").
 *
 * This suite asserts that an `Authorization: Bearer <token>` header
 * alone — no `PHPSESSID` cookie, no `X-CSRF-Token` — is sufficient to
 * complete the TODO list / create / read round-trip that stateless
 * MCP / agent clients exercise.
 *
 * The tests require **two coordinated env vars**:
 *
 *   - `NENE_AGENT_BEARER_TOKEN`  set on the running NeNe container
 *     (so the server validates the incoming token).
 *   - `NENE_HTTP_BEARER_TOKEN`   set in the test process (so the test
 *     sends the same value).
 *
 * Both must hold the same value. When `NENE_HTTP_BEARER_TOKEN` is unset
 * (the framework's default CI configuration), every test in this class
 * skips — matching the established pattern of `HttpErrorExposureTest`
 * with `NENE_HTTP_ERROR_BASE_URL`.
 *
 * Run via:
 *
 *     NENE_AGENT_BEARER_TOKEN=<token> docker compose up -d app
 *     NENE_HTTP_BEARER_TOKEN=<same token> \
 *         NENE_HTTP_BASE_URL=http://127.0.0.1:8080 \
 *         composer test:http
 */
final class HttpBearerAuthTest extends TestCase
{
    private const BEARER_TOKEN_ENV = 'NENE_HTTP_BEARER_TOKEN';
    private const TEST_TODO_PREFIX = 'HTTP Bearer test ';

    private string $bearerToken;
    private HttpClient $client;

    protected function setUp(): void
    {
        $token = (string)(getenv(self::BEARER_TOKEN_ENV) ?: '');
        if ($token === '') {
            self::markTestSkipped(self::BEARER_TOKEN_ENV . ' is not configured.');
        }
        $baseUrl = (string)(getenv('NENE_HTTP_BASE_URL') ?: '');
        if ($baseUrl === '') {
            self::markTestSkipped('NENE_HTTP_BASE_URL is not configured.');
        }
        $this->bearerToken = $token;
        $this->client = new HttpClient($baseUrl);
        try {
            $this->client->request('GET', '/api-docs/openapi.php');
        } catch (\RuntimeException $exception) {
            self::markTestSkipped('HTTP runtime is not reachable: ' . $exception->getMessage());
        }
    }

    public function testListTodosWithBearerReturns200AndPayload(): void
    {
        $response = $this->bearerClient()->request('GET', '/todo/index', null, $this->bearerHeader());
        $payload = $response->json();

        self::assertSame(200, $response->statusCode());
        self::assertSame(true, $payload['Result']);
        self::assertSame('success', $payload['Data']['status']);
        self::assertIsArray($payload['Data']['todos']);
    }

    public function testCreateTodoWithBearerReturns200WithoutCsrfHeader(): void
    {
        $response = $this->bearerClient()->json(
            'POST',
            '/todo/index',
            ['title' => self::TEST_TODO_PREFIX . 'create probe'],
            $this->bearerHeader()
        );
        $payload = $response->json();

        self::assertSame(200, $response->statusCode());
        self::assertSame('success', $payload['Data']['status']);
        self::assertSame(self::TEST_TODO_PREFIX . 'create probe', $payload['Data']['todo']['title']);
        self::assertIsInt($payload['Data']['todo']['id']);
    }

    public function testReadTodoItemWithBearerRoundTrips(): void
    {
        $client = $this->bearerClient();
        $createResponse = $client->json(
            'POST',
            '/todo/index',
            ['title' => self::TEST_TODO_PREFIX . 'read probe'],
            $this->bearerHeader()
        );
        $createdId = (int)$createResponse->json()['Data']['todo']['id'];

        $readResponse = $client->request('GET', '/todo/item/id_' . $createdId, null, $this->bearerHeader());
        $payload = $readResponse->json();

        self::assertSame(200, $readResponse->statusCode());
        self::assertSame($createdId, (int)$payload['Data']['todo']['id']);
        self::assertSame(self::TEST_TODO_PREFIX . 'read probe', $payload['Data']['todo']['title']);
    }

    public function testInvalidBearerReturnsSessionClosed(): void
    {
        $client = $this->bearerClient();
        $response = $client->request('GET', '/todo/index', null, [
            'Authorization' => 'Bearer ' . $this->bearerToken . '-tampered',
        ]);
        $payload = $response->json();

        self::assertSame(401, $response->statusCode());
        self::assertSame('SESSION-CLOSED', $payload['Data']['errorCode']);
    }

    public function testMissingAuthorizationReturnsSessionClosed(): void
    {
        $client = $this->bearerClient();
        $response = $client->request('GET', '/todo/index');
        $payload = $response->json();

        self::assertSame(401, $response->statusCode());
        self::assertSame('SESSION-CLOSED', $payload['Data']['errorCode']);
    }

    /**
     * A fresh client without any cookies or stored CSRF token — the
     * Bearer path runs without per-client state.
     */
    private function bearerClient(): HttpClient
    {
        return new HttpClient((string)getenv('NENE_HTTP_BASE_URL'));
    }

    /**
     * @return array<string,string>
     */
    private function bearerHeader(): array
    {
        return ['Authorization' => 'Bearer ' . $this->bearerToken];
    }
}
