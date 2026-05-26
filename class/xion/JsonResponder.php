<?php

/**
 * AYANE : ayane.co.jp
 * powered by NENE.
 *
 * PHP Version >= 8.4
 *
 * @package   AYANE
 * @author    hideyukiMORI <info@ayane.co.jp>
 * @copyright 2021 AYANE
 * @license   https://opensource.org/licenses/MIT MIT License
 * @link      https://ayane.co.jp/
 */

declare(strict_types=1);

namespace Nene\Xion;

/**
 * Handles JSON request and response boundaries.
 */
class JsonResponder
{
    /**
     * Read the JSON request body.
     *
     * @return array<string,mixed> Decoded request body.
     */
    final public static function inputJsonToArray(): array
    {
        $postJson = file_get_contents('php://input');
        if ($postJson === false || $postJson === '') {
            return [];
        }
        $jsonArray = json_decode($postJson, true);
        return is_array($jsonArray) ? $jsonArray : [];
    }

    /**
     * Build the standard successful JSON envelope.
     *
     * @param array<string,mixed> $data Response data.
     *
     * @return array<string,mixed> JSON envelope.
     */
    final public static function envelope(array $data): array
    {
        return [
            'Result' => true,
            'Data' => $data,
        ];
    }

    /**
     * Build the legacy error JSON envelope.
     *
     * @param string $errorCode    Error code.
     * @param string $errorMessage Error message.
     *
     * @return array<string,mixed> JSON error envelope.
     */
    final public static function errorEnvelope(string $errorCode, string $errorMessage): array
    {
        return [
            'Result' => false,
            'Error' => [
                'ErrorCode' => $errorCode,
                'ErrorMessage' => $errorMessage,
            ],
        ];
    }

    /**
     * Emit the standard successful JSON envelope.
     *
     * @param array<string,mixed> $data Response data.
     *
     * @return never
     */
    final public static function outputArray(array $data): never
    {
        throw new HttpTermination(self::responseArray($data));
    }

    /**
     * Emit the legacy error JSON envelope.
     *
     * @param string $errorCode    Error code.
     * @param string $errorMessage Error message.
     *
     * @return never
     */
    final public static function outputError(string $errorCode, string $errorMessage): never
    {
        throw new HttpTermination(self::errorResponse($errorCode, $errorMessage));
    }

    /**
     * Build a successful JSON HTTP response.
     *
     * @param array<string,mixed> $data Response data.
     *
     * @return HttpResponse JSON response.
     */
    final public static function responseArray(array $data): HttpResponse
    {
        return self::response(self::envelope($data), self::statusCodeForData($data));
    }

    /**
     * Build a legacy error JSON HTTP response.
     *
     * @param string $errorCode    Error code.
     * @param string $errorMessage Error message.
     *
     * @return HttpResponse JSON response.
     */
    final public static function errorResponse(string $errorCode, string $errorMessage): HttpResponse
    {
        return self::response(self::errorEnvelope($errorCode, $errorMessage));
    }

    /**
     * Build a JSON response without emitting it.
     *
     * @param array<string,mixed> $response Response envelope.
     *
     * @return HttpResponse JSON response.
     */
    final public static function response(array $response, int $statusCode = 200): HttpResponse
    {
        return new HttpResponse(
            $statusCode,
            ['Content-Type' => 'application/json; charset=utf-8'],
            self::encode($response)
        );
    }

    /**
     * Emit a JSON response without terminating the process.
     *
     * @param array<string,mixed> $response Response envelope.
     *
     * @return void
     */
    final public static function emit(array $response): void
    {
        HttpEmitter::emit(self::response($response));
    }

    /**
     * Encode data for a JSON HTTP body.
     *
     * @param array<string,mixed> $response Response envelope.
     *
     * @return string JSON body.
     */
    private static function encode(array $response): string
    {
        return json_encode($response, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    /**
     * Determine HTTP status from an API response payload.
     *
     * @param array<string,mixed> $data API response data.
     *
     * @return integer HTTP status code.
     */
    private static function statusCodeForData(array $data): int
    {
        if (($data['status'] ?? '') === 'failure' && is_string($data['errorCode'] ?? null)) {
            return ErrorCode::getInstance()->getHttpStatus($data['errorCode']);
        }
        return 200;
    }
}
