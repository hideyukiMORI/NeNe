<?php

declare(strict_types=1);

namespace Nene\Tests\Http;

require_once __DIR__ . '/HttpRuntimeTestCase.php';

final class OpenApiRuntimeContractTest extends HttpRuntimeTestCase
{
    /*
     * This test intentionally uses a lightweight line-based OpenAPI reader.
     * Its current purpose is a runtime smoke check: documented REST operations
     * should exist, and observed HTTP statuses should be listed in the contract.
     *
     * It is not a general YAML parser. When the OpenAPI contract grows beyond
     * this simple path/method/status check, replace this extraction logic with
     * a dev dependency such as symfony/yaml before adding richer assertions.
     */
    public function testDocumentedRestOperationsRespondWithDocumentedStatuses(): void
    {
        $openApiResponse = $this->client->request('GET', '/api-docs/openapi.php');
        $openApi = $openApiResponse->body();
        $operations = $this->documentedOperations($openApi);

        $examples = [
            ['POST', '/session/login', '/session/login', ['user_id' => 'admin', 'user_pass' => 'admin']],
            ['POST', '/session/logout', '/session/logout', []],
            ['GET', '/todo/index', '/todo/index', null],
            ['POST', '/todo/index', '/todo/index', ['title' => self::TEST_TODO_PREFIX . 'contract']],
            ['PUT', '/todo/item/id_{id}', '/todo/item/id_1', ['is_completed' => true]],
            ['DELETE', '/todo/item/id_{id}', '/todo/item/id_1', null]
        ];

        foreach ($examples as [$method, $documentedPath, $runtimePath, $body]) {
            self::assertContains(
                [$method, $documentedPath],
                $operations,
                $method . ' ' . $documentedPath . ' is missing from OpenAPI.'
            );

            $client = $this->newClient();
            $response = is_array($body)
                ? $client->json($method, $runtimePath, $body)
                : $client->request($method, $runtimePath);

            self::assertContains(
                (string)$response->statusCode(),
                $this->documentedStatuses($openApi, $documentedPath, $method),
                $method . ' ' . $documentedPath . ' returned undocumented status ' . $response->statusCode()
            );
        }
    }

    /**
     * @return array<int,array{0:string,1:string}>
     */
    private function documentedOperations(string $openApi): array
    {
        $operations = [];
        $currentPath = null;
        foreach (explode("\n", $openApi) as $line) {
            if (preg_match('/^  (\/[^:]+):$/', $line, $matches)) {
                $currentPath = $matches[1];
                continue;
            }
            if ($currentPath !== null && preg_match('/^    (get|post|put|patch|delete):$/', $line, $matches)) {
                $operations[] = [strtoupper($matches[1]), $currentPath];
            }
        }
        return $operations;
    }

    /**
     * @return array<int,string>
     */
    private function documentedStatuses(string $openApi, string $path, string $method): array
    {
        $lines = explode("\n", $openApi);
        $insidePath = false;
        $insideMethod = false;
        $insideResponses = false;
        $statuses = [];

        foreach ($lines as $line) {
            if (preg_match('/^  (\/[^:]+):$/', $line, $matches)) {
                $insidePath = $matches[1] === $path;
                $insideMethod = false;
                $insideResponses = false;
                continue;
            }
            if (!$insidePath) {
                continue;
            }
            if (preg_match('/^    (get|post|put|patch|delete):$/', $line, $matches)) {
                $insideMethod = strtoupper($matches[1]) === strtoupper($method);
                $insideResponses = false;
                continue;
            }
            if ($insideMethod && preg_match('/^      responses:$/', $line)) {
                $insideResponses = true;
                continue;
            }
            if ($insideResponses && preg_match('/^        "(\d{3})":$/', $line, $matches)) {
                $statuses[] = $matches[1];
                continue;
            }
            if ($insideResponses && preg_match('/^      [a-zA-Z_][^:]*:/', $line)) {
                break;
            }
        }

        return $statuses;
    }
}
