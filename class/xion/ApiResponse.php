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
 * @license   https://choosealicense.com/no-permission/ NO LICENSE
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
    private $errorCode;

    /**
     * CONSTRUCTOR.
     */
    public function __construct()
    {
        $this->errorCode = ErrorCode::getInstance();
    }

    /**
     * Build a success response.
     *
     * @param array<string,mixed> $data Response data.
     *
     * @return array<string,mixed> API response.
     */
    public function success(array $data = []): array
    {
        return array_merge([
            'status' => 'success',
            'errorCode' => ''
        ], $data);
    }

    /**
     * Build a failure response.
     *
     * @param string $errorCode Error code.
     *
     * @return array<string,string> API response.
     */
    public function failure(string $errorCode): array
    {
        return [
            'status' => 'failure',
            'errorCode' => $errorCode,
            'errorMessage' => $this->errorCode->getErrorText($errorCode)
        ];
    }
}
