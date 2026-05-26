<?php

declare(strict_types=1);

namespace Nene\Tests\Http;

require_once __DIR__ . '/HttpRuntimeTestCase.php';

/**
 * HTTP runtime tests for the Todo REST API.
 *
 * This file is the canonical per-entity CRUD test example. Follow this pattern
 * when adding HTTP tests for a new resource:
 *
 *  1. Extend HttpRuntimeTestCase — it handles client setup, admin login helpers,
 *     and auto-cleanup of created resources via cleanupTodoIds / tearDown.
 *
 *  2. Prefix test data titles with TEST_TODO_PREFIX so setUp() can wipe any
 *     leftover rows from a prior run before each test starts.
 *
 *  3. Use loginAsAdmin() to get an authenticated client with a CSRF token.
 *     The client remembers the session cookie and CSRF token automatically
 *     after the first POST response that contains csrfToken.
 *
 *  4. Use createTodo() to create and track rows — the base tearDown() deletes
 *     them even when an assertion fails mid-test.
 *
 *  5. Test one behaviour per method; keep assertions tight.
 *
 * Authentication model for the Todo API:
 *  - All endpoints require a valid login session (401 when unauthenticated).
 *  - State-changing methods (POST, PUT, DELETE) also require X-CSRF-Token.
 *    The HttpClient sends it automatically after loginAsAdmin().
 *
 * See docs/tutorials/building-a-service.md §Add Tests for the companion
 * narrative, and docs/development/testing.md for test infrastructure details.
 */
final class TodoTest extends HttpRuntimeTestCase
{
    // ------------------------------------------------------------------
    // GET /todo/index — list
    // ------------------------------------------------------------------

    public function testListReturnsSuccessWithTodosArray(): void
    {
        $this->loginAsAdmin();
        $response = $this->client->request('GET', '/todo/index');
        $payload  = $response->json();

        self::assertSame(200, $response->statusCode());
        self::assertSame('success', $payload['Data']['status']);
        self::assertIsArray($payload['Data']['todos']);
    }

    public function testListRequiresAuthentication(): void
    {
        // Fresh client — no session, no CSRF token.
        $anonymous = $this->newClient();
        $response  = $anonymous->request('GET', '/todo/index');

        self::assertSame(401, $response->statusCode());
    }

    // ------------------------------------------------------------------
    // POST /todo/index — create
    // ------------------------------------------------------------------

    public function testCreateReturnsNewTodoOnSuccess(): void
    {
        $this->loginAsAdmin();
        $title = self::TEST_TODO_PREFIX . 'create-success';
        $todo  = $this->createTodo($title);

        self::assertIsInt($todo['id']);
        self::assertSame($title, $todo['title']);
        self::assertFalse($todo['is_completed']);
    }

    public function testCreateReturnsTodoIdFieldAsInteger(): void
    {
        // PDO returns all columns as strings by default. The controller's
        // normalizeRow() must cast 'id' to int and 'is_completed' to bool.
        $this->loginAsAdmin();
        $todo = $this->createTodo(self::TEST_TODO_PREFIX . 'type-check');

        self::assertSame('integer', gettype($todo['id']));
        self::assertSame('boolean', gettype($todo['is_completed']));
    }

    public function testCreateFailsWithoutTitle(): void
    {
        $this->loginAsAdmin();
        $response = $this->client->json('POST', '/todo/index', ['title' => '']);
        $payload  = $response->json();

        self::assertSame(400, $response->statusCode());
        self::assertSame('failure', $payload['Data']['status']);
        self::assertSame('TODO-TITLE-REQUIRED', $payload['Data']['errorCode']);
    }

    public function testCreateRequiresAuthentication(): void
    {
        $anonymous = $this->newClient();
        $response  = $anonymous->json('POST', '/todo/index', ['title' => 'no-auth']);

        self::assertSame(401, $response->statusCode());
    }

    // ------------------------------------------------------------------
    // GET /todo/item/id_{id} — read one
    // ------------------------------------------------------------------

    public function testGetItemReturnsTodoById(): void
    {
        $this->loginAsAdmin();
        $created  = $this->createTodo(self::TEST_TODO_PREFIX . 'get-item');
        $response = $this->client->request('GET', '/todo/item/id_' . $created['id']);
        $payload  = $response->json();

        self::assertSame(200, $response->statusCode());
        self::assertSame('success', $payload['Data']['status']);
        self::assertSame($created['id'], $payload['Data']['todo']['id']);
        self::assertSame($created['title'], $payload['Data']['todo']['title']);
    }

    public function testGetItemReturns404ForMissingId(): void
    {
        $this->loginAsAdmin();
        // Use a very high id that will never exist in the test database.
        $response = $this->client->request('GET', '/todo/item/id_999999999');
        $payload  = $response->json();

        self::assertSame(404, $response->statusCode());
        self::assertSame('TODO-NOT-FOUND', $payload['Data']['errorCode']);
    }

    public function testGetItemReturns400ForNonNumericId(): void
    {
        $this->loginAsAdmin();
        $response = $this->client->request('GET', '/todo/item/id_abc');
        $payload  = $response->json();

        self::assertSame(400, $response->statusCode());
        self::assertSame('TODO-ID-REQUIRED', $payload['Data']['errorCode']);
    }

    public function testGetItemRequiresAuthentication(): void
    {
        $anonymous = $this->newClient();
        $response  = $anonymous->request('GET', '/todo/item/id_1');

        self::assertSame(401, $response->statusCode());
    }

    // ------------------------------------------------------------------
    // PUT /todo/item/id_{id} — update
    // ------------------------------------------------------------------

    public function testUpdateChangesTitle(): void
    {
        $this->loginAsAdmin();
        $todo     = $this->createTodo(self::TEST_TODO_PREFIX . 'update-before');
        $newTitle = self::TEST_TODO_PREFIX . 'update-after';
        $response = $this->client->json('PUT', '/todo/item/id_' . $todo['id'], [
            'title' => $newTitle,
        ]);
        $payload = $response->json();

        self::assertSame(200, $response->statusCode());
        self::assertSame($newTitle, $payload['Data']['todo']['title']);
    }

    public function testUpdateMarksCompleted(): void
    {
        $this->loginAsAdmin();
        $todo     = $this->createTodo(self::TEST_TODO_PREFIX . 'update-complete');
        $response = $this->client->json('PUT', '/todo/item/id_' . $todo['id'], [
            'is_completed' => true,
        ]);
        $payload = $response->json();

        self::assertSame(200, $response->statusCode());
        self::assertTrue($payload['Data']['todo']['is_completed']);
    }

    public function testUpdateReturns404ForMissingId(): void
    {
        $this->loginAsAdmin();
        $response = $this->client->json('PUT', '/todo/item/id_999999999', ['title' => 'ghost']);
        $payload  = $response->json();

        self::assertSame(404, $response->statusCode());
        self::assertSame('TODO-NOT-FOUND', $payload['Data']['errorCode']);
    }

    // ------------------------------------------------------------------
    // DELETE /todo/item/id_{id} — delete
    // ------------------------------------------------------------------

    public function testDeleteRemovesTodo(): void
    {
        $this->loginAsAdmin();
        $todo = $this->createTodo(self::TEST_TODO_PREFIX . 'delete-me');

        $deleteResponse = $this->client->request('DELETE', '/todo/item/id_' . $todo['id']);
        self::assertSame(200, $deleteResponse->statusCode());

        // A second GET should return 404.
        $getResponse = $this->client->request('GET', '/todo/item/id_' . $todo['id']);
        self::assertSame(404, $getResponse->statusCode());
    }

    public function testDeleteReturns404ForMissingId(): void
    {
        $this->loginAsAdmin();
        $response = $this->client->request('DELETE', '/todo/item/id_999999999');
        $payload  = $response->json();

        self::assertSame(404, $response->statusCode());
        self::assertSame('TODO-NOT-FOUND', $payload['Data']['errorCode']);
    }

    public function testDeleteRequiresAuthentication(): void
    {
        $this->loginAsAdmin();
        $todo = $this->createTodo(self::TEST_TODO_PREFIX . 'delete-unauth');

        $anonymous = $this->newClient(); // separate client, no session
        $response  = $anonymous->request('DELETE', '/todo/item/id_' . $todo['id']);

        self::assertSame(401, $response->statusCode());
    }

    // ------------------------------------------------------------------
    // Method-not-allowed coverage
    // ------------------------------------------------------------------

    public function testIndexReturns405ForUnsupportedMethod(): void
    {
        $this->loginAsAdmin();
        $response = $this->client->request('PATCH', '/todo/index');

        self::assertSame(405, $response->statusCode());
    }
}
