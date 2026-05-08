<?php

declare(strict_types=1);

namespace Nene\Tests\Http;

/**
 * Small HTTP client for runtime smoke tests.
 */
final class HttpClient
{
    /**
     * Base URL without trailing slash.
     *
     * @var string
     */
    private $baseUrl;

    /**
     * Cookie jar keyed by cookie name.
     *
     * @var array<string,string>
     */
    private $cookies = [];

    public function __construct(string $baseUrl)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * Send a JSON request.
     *
     * @param array<string,mixed> $payload JSON payload.
     * @param array<string,string> $headers Request headers.
     *
     * @return HttpResponse
     */
    public function json(string $method, string $path, array $payload = [], array $headers = []): HttpResponse
    {
        return $this->request(
            $method,
            $path,
            json_encode($payload, JSON_THROW_ON_ERROR),
            array_merge(['Content-Type' => 'application/json'], $headers)
        );
    }

    /**
     * Send an HTTP request.
     *
     * @param array<string,string> $headers Request headers.
     *
     * @return HttpResponse
     */
    public function request(string $method, string $path, ?string $body = null, array $headers = []): HttpResponse
    {
        $requestHeaders = $this->formatHeaders($headers);
        $cookieHeader = $this->buildCookieHeader();
        if ($cookieHeader !== '') {
            $requestHeaders[] = 'Cookie: ' . $cookieHeader;
        }

        $context = stream_context_create([
            'http' => [
                'method' => strtoupper($method),
                'header' => implode("\r\n", $requestHeaders),
                'content' => $body ?? '',
                'ignore_errors' => true,
                'timeout' => 5
            ]
        ]);

        $responseHeaders = [];
        $responseBody = @file_get_contents($this->baseUrl . $path, false, $context);
        if (isset($http_response_header) && is_array($http_response_header)) {
            $responseHeaders = $http_response_header;
        }
        if ($responseBody === false) {
            throw new \RuntimeException('HTTP request failed: ' . $this->baseUrl . $path);
        }

        $response = HttpResponse::fromRawHeaders($responseHeaders, $responseBody);
        $this->storeCookies($response->headers());
        return $response;
    }

    /**
     * @param array<string,string> $headers Request headers.
     *
     * @return array<int,string>
     */
    private function formatHeaders(array $headers): array
    {
        $formatted = [];
        foreach ($headers as $name => $value) {
            $formatted[] = $name . ': ' . $value;
        }
        return $formatted;
    }

    private function buildCookieHeader(): string
    {
        $pairs = [];
        foreach ($this->cookies as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }
        return implode('; ', $pairs);
    }

    /**
     * @param array<string,array<int,string>> $headers Response headers.
     *
     * @return void
     */
    private function storeCookies(array $headers): void
    {
        foreach ($headers['set-cookie'] ?? [] as $cookieHeader) {
            $cookiePair = explode(';', $cookieHeader, 2)[0];
            $parts = explode('=', $cookiePair, 2);
            if (count($parts) === 2) {
                $this->cookies[$parts[0]] = $parts[1];
            }
        }
    }
}

/**
 * Runtime HTTP response value object for tests.
 */
final class HttpResponse
{
    /**
     * HTTP status code.
     *
     * @var int
     */
    private $statusCode;

    /**
     * Response headers keyed by lower-case header name.
     *
     * @var array<string,array<int,string>>
     */
    private $headers;

    /**
     * Response body.
     *
     * @var string
     */
    private $body;

    /**
     * @param array<string,array<int,string>> $headers Response headers.
     */
    private function __construct(int $statusCode, array $headers, string $body)
    {
        $this->statusCode = $statusCode;
        $this->headers = $headers;
        $this->body = $body;
    }

    /**
     * @param array<int,string> $rawHeaders Raw response headers.
     */
    public static function fromRawHeaders(array $rawHeaders, string $body): self
    {
        $statusCode = 0;
        $headers = [];
        foreach ($rawHeaders as $headerLine) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $headerLine, $matches)) {
                $statusCode = (int)$matches[1];
                continue;
            }
            $parts = explode(':', $headerLine, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $name = strtolower(trim($parts[0]));
            $headers[$name][] = trim($parts[1]);
        }
        return new self($statusCode, $headers, $body);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function body(): string
    {
        return $this->body;
    }

    /**
     * @return array<string,array<int,string>>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function headerLine(string $name): string
    {
        return implode(', ', $this->headers[strtolower($name)] ?? []);
    }

    /**
     * @return array<string,mixed>
     */
    public function json(): array
    {
        $decoded = json_decode($this->body, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Response body is not a JSON object: ' . $this->body);
        }
        return $decoded;
    }
}
