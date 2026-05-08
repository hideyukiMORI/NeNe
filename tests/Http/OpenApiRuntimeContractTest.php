<?php

declare(strict_types=1);

namespace Nene\Tests\Http;

use Symfony\Component\Yaml\Yaml;

require_once __DIR__ . '/HttpRuntimeTestCase.php';

final class OpenApiRuntimeContractTest extends HttpRuntimeTestCase
{
    /*
     * This is still a runtime smoke check rather than a full OpenAPI validator:
     * documented REST operations should exist, and observed HTTP statuses
     * should be listed in the contract. symfony/yaml is used so this test reads
     * the contract as structured data instead of depending on indentation.
     */
    public function testDocumentedRestOperationsRespondWithDocumentedStatuses(): void
    {
        $openApiResponse = $this->client->request('GET', '/api-docs/openapi.php');
        $openApi = $this->parseOpenApi($openApiResponse->body());
        $operations = $this->documentedOperations($openApi);

        $examples = [
            ['GET', '/health/index', '/health/index', null],
            ['POST', '/session/login', '/session/login', ['user_id' => 'admin', 'user_pass' => 'admin']],
            ['POST', '/session/logout', '/session/logout', []],
            ['GET', '/todo/index', '/todo/index', null],
            ['POST', '/todo/index', '/todo/index', ['title' => self::TEST_TODO_PREFIX . 'contract']],
            ['PUT', '/todo/item/id_{id}', '/todo/item/id_1', ['is_completed' => true]],
            ['DELETE', '/todo/item/id_{id}', '/todo/item/id_1', null],
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
     * @return array<string,mixed>
     */
    private function parseOpenApi(string $yaml): array
    {
        $parsed = Yaml::parse($yaml);

        self::assertIsArray($parsed);
        return $parsed;
    }

    /**
     * @param array<string,mixed> $openApi Parsed OpenAPI document.
     *
     * @return array<int,array{0:string,1:string}>
     */
    private function documentedOperations(array $openApi): array
    {
        $operations = [];
        $paths = $openApi['paths'] ?? [];
        self::assertIsArray($paths);

        foreach ($paths as $path => $pathItem) {
            if (!is_string($path) || !is_array($pathItem)) {
                continue;
            }
            foreach (['get', 'post', 'put', 'patch', 'delete'] as $method) {
                if (array_key_exists($method, $pathItem)) {
                    $operations[] = [strtoupper($method), $path];
                }
            }
        }
        return $operations;
    }

    /**
     * @param array<string,mixed> $openApi Parsed OpenAPI document.
     *
     * @return array<int,string>
     */
    private function documentedStatuses(array $openApi, string $path, string $method): array
    {
        $responses = $openApi['paths'][$path][strtolower($method)]['responses'] ?? [];
        self::assertIsArray($responses);

        return array_map('strval', array_keys($responses));
    }
}
