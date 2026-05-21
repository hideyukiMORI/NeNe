<?php

declare(strict_types=1);

namespace Nene\Tests\Http;

use Symfony\Component\Yaml\Yaml;

require_once __DIR__ . '/HttpRuntimeTestCase.php';

final class OpenApiRuntimeContractTest extends HttpRuntimeTestCase
{
    private const PATH_PARAM_SENTINEL = '999999999';
    private const SKIP_EXTENSION = 'x-nene-runtime-probe';
    private const SKIP_VALUE = 'skip';

    /*
     * This is still a runtime smoke check rather than a full OpenAPI validator:
     * every documented REST operation should be probeable, and the observed
     * HTTP status should be listed in the contract. symfony/yaml is used so
     * this test reads the contract as structured data instead of depending on
     * indentation.
     *
     * Each operation is exercised with a fresh client (no shared cookie or
     * CSRF state), so state-changing endpoints that require auth typically
     * land on the documented 401 / 403 branch — that is the intent. The
     * probe verifies the contract for failure paths; the dedicated per-entity
     * tests (TodoTest, MemoAuthTest, etc.) cover happy paths.
     *
     * Adding a new endpoint to docs/api/openapi.yaml automatically adds it to
     * this test. To opt an operation out (e.g. one with destructive side
     * effects that should not be probed), set the OpenAPI vendor extension
     * `x-nene-runtime-probe: skip` on the operation.
     */
    public function testDocumentedRestOperationsRespondWithDocumentedStatuses(): void
    {
        $openApiResponse = $this->client->request('GET', '/api-docs/openapi.php');
        $openApi = $this->parseOpenApi($openApiResponse->body());
        $operations = $this->documentedOperations($openApi);

        self::assertNotEmpty($operations, 'OpenAPI document contains no operations to probe.');

        foreach ($operations as $operation) {
            [$method, $documentedPath, $operationObject] = $operation;

            if ($this->isSkipped($operationObject)) {
                continue;
            }

            $runtimePath = $this->materializePath($documentedPath, $operationObject);
            $body = $this->extractExampleBody($operationObject);

            $client = $this->newClient();
            $response = is_array($body)
                ? $client->json($method, $runtimePath, $body)
                : $client->request($method, $runtimePath);

            $documentedStatuses = $this->documentedStatuses($openApi, $documentedPath, $method);
            self::assertContains(
                (string)$response->statusCode(),
                $documentedStatuses,
                sprintf(
                    '%s %s probed at %s returned undocumented status %d (documented: %s).',
                    $method,
                    $documentedPath,
                    $runtimePath,
                    $response->statusCode(),
                    implode(', ', $documentedStatuses)
                )
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
     * @return array<int,array{0:string,1:string,2:array<string,mixed>}>
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
                if (!array_key_exists($method, $pathItem)) {
                    continue;
                }
                $operationObject = is_array($pathItem[$method]) ? $pathItem[$method] : [];
                $operations[] = [strtoupper($method), $path, $operationObject];
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

    /**
     * Substitute path parameters with a sentinel value (defaults to 999999999),
     * so destructive probes hit the documented 404 branch rather than a real
     * row. Per-parameter examples in the OpenAPI document take priority when
     * present.
     *
     * @param array<string,mixed> $operationObject
     */
    private function materializePath(string $documentedPath, array $operationObject): string
    {
        $runtimePath = $documentedPath;
        $parameters = isset($operationObject['parameters']) && is_array($operationObject['parameters'])
            ? $operationObject['parameters']
            : [];

        foreach ($parameters as $parameter) {
            if (!is_array($parameter) || ($parameter['in'] ?? null) !== 'path') {
                continue;
            }
            $name = (string)($parameter['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $value = $parameter['example'] ?? self::PATH_PARAM_SENTINEL;
            $runtimePath = str_replace('{' . $name . '}', (string)$value, $runtimePath);
        }

        // Generic fallback for any unresolved {placeholder}.
        return (string)preg_replace('/\{[^}]+\}/', self::PATH_PARAM_SENTINEL, $runtimePath);
    }

    /**
     * Extract the first JSON example from the operation's request body, if any.
     * Returns null when the operation has no body (typical for GET / DELETE).
     *
     * @param array<string,mixed> $operationObject
     *
     * @return array<string,mixed>|null
     */
    private function extractExampleBody(array $operationObject): ?array
    {
        $jsonContent = $operationObject['requestBody']['content']['application/json'] ?? null;
        if (!is_array($jsonContent)) {
            return null;
        }

        if (isset($jsonContent['examples']) && is_array($jsonContent['examples'])) {
            foreach ($jsonContent['examples'] as $example) {
                if (is_array($example) && isset($example['value']) && is_array($example['value'])) {
                    return $example['value'];
                }
            }
        }

        if (isset($jsonContent['example']) && is_array($jsonContent['example'])) {
            return $jsonContent['example'];
        }

        return [];
    }

    /**
     * @param array<string,mixed> $operationObject
     */
    private function isSkipped(array $operationObject): bool
    {
        return ($operationObject[self::SKIP_EXTENSION] ?? null) === self::SKIP_VALUE;
    }
}
