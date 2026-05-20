<?php

declare(strict_types=1);

namespace Nene\Xion;

use RuntimeException;

/**
 * Throwable that carries a NeNe error code to the top-level request handler.
 *
 * Application code may throw `DomainException` from inside a controller method,
 * a service, a mapper, or a `TransactionManager::run()` callback when it needs
 * to surface a domain-level failure (for example, a missing referenced row, a
 * duplicate value, or a business-rule violation) without paying for a manual
 * detour around `htdocs/index.php`'s generic 500 fallback.
 *
 * The top-level handler in `htdocs/index.php` catches this class and converts
 * it to the standard `ApiResponse::failure($errorCode)` JSON envelope, picking
 * up the HTTP status defined in `config/error_codes.php`. If thrown from inside
 * a `TransactionManager::run()` callback, the transaction is rolled back first
 * by `TransactionManager` itself, then the catch-all converts the exception to
 * the JSON failure response.
 *
 * Error codes must already exist in `config/error_codes.php` so the HTTP status
 * and message can be resolved. Throwing an unknown code produces an
 * `Internal Server Error` style 500 fallback, which is intentional — domain
 * errors are part of the API contract and must be registered first.
 */
final class DomainException extends RuntimeException
{
    /**
     * Error code from `config/error_codes.php`.
     */
    private string $errorCode;

    /**
     * @param string $errorCode Error code declared in `config/error_codes.php`.
     */
    public function __construct(string $errorCode)
    {
        parent::__construct($errorCode);
        $this->errorCode = $errorCode;
    }

    /**
     * Error code declared in `config/error_codes.php`.
     */
    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
