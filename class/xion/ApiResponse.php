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
 * Builds REST response payloads.
 */
class ApiResponse
{
    /**
     * Shared error code registry.
     *
     * @var ErrorCode
     */
    private ErrorCode $errorCode;

    /**
     * CONSTRUCTOR.
     */
    public function __construct()
    {
        $this->errorCode = ErrorCode::getInstance();
    }

    /**
     * Build a success response envelope.
     *
     * The returned array always includes `status: 'success'` and
     * `errorCode: ''`; any keys supplied in `$data` are merged on top
     * (caller-supplied keys win, since `array_merge` keeps the rightmost
     * value for duplicate keys). The envelope is wrapped by
     * `JsonResponder::responseArray()` so the final REST shape is
     * `{ Result: true, Data: { status: 'success', errorCode: '', ... } }`.
     *
     * @param array<string,mixed> $data Domain-specific data to merge into the envelope.
     *
     * @return array<string,mixed> Success envelope with caller keys merged.
     */
    public function success(array $data = []): array
    {
        return array_merge([
            'status' => 'success',
            'errorCode' => '',
        ], $data);
    }

    /**
     * Build a failure response envelope.
     *
     * The shape is exactly three keys — `status`, `errorCode`,
     * `errorMessage` — and never carries caller-supplied data. The
     * PHPDoc uses the array-shape form so static analysis can
     * distinguish failure responses from success responses by the
     * presence of `errorMessage` (#405, eval report PR #401 § 2).
     *
     * @return array{status: 'failure', errorCode: string, errorMessage: string}
     */
    public function failure(string $errorCode): array
    {
        return [
            'status' => 'failure',
            'errorCode' => $errorCode,
            'errorMessage' => $this->errorCode->getErrorText($errorCode),
        ];
    }
}
