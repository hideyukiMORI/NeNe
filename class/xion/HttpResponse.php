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
 * Small immutable HTTP response value object.
 */
final class HttpResponse
{
    /**
     * @param integer              $statusCode HTTP status code.
     * @param array<string,string> $headers    Response headers.
     * @param string               $body       Response body.
     */
    public function __construct(
        private readonly int $statusCode = 200,
        private readonly array $headers = [],
        private readonly string $body = ''
    ) {
    }

    final public static function text(string $body, int $statusCode = 200): self
    {
        return new self($statusCode, ['Content-Type' => 'text/plain; charset=utf-8'], $body);
    }

    final public static function html(string $body, int $statusCode = 200): self
    {
        return new self($statusCode, ['Content-Type' => 'text/html; charset=utf-8'], $body);
    }

    /**
     * @param array<string,string> $headers Additional headers.
     */
    final public static function redirect(string $uri, int $statusCode = 302, array $headers = []): self
    {
        return new self($statusCode, array_merge($headers, ['Location' => $uri]));
    }

    /**
     * Build a binary file response (Content-Type set by `$mime`; optionally
     * marks the response as a download with `Content-Disposition: attachment;
     * filename="..."`). Used by {@see ControllerBase::sendFile()}.
     *
     * The body is the full file contents; callers requiring streamed delivery
     * of multi-gigabyte files should reach for `readfile()` outside this value
     * object (out of scope for the small-app surface NeNe targets).
     *
     * @param string      $body         Raw file contents.
     * @param string      $mime         Response `Content-Type` (e.g. `image/png`).
     * @param string|null $downloadName Optional filename for `Content-Disposition`.
     * @param integer     $statusCode   HTTP status code (default 200).
     */
    final public static function file(
        string $body,
        string $mime,
        ?string $downloadName = null,
        int $statusCode = 200
    ): self {
        $headers = ['Content-Type' => $mime];
        if ($downloadName !== null) {
            $safe = str_replace(['"', "\r", "\n"], '', $downloadName);
            $headers['Content-Disposition'] = 'attachment; filename="' . $safe . '"';
        }
        return new self($statusCode, $headers, $body);
    }

    final public function statusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return array<string,string>
     */
    final public function headers(): array
    {
        return $this->headers;
    }

    final public function body(): string
    {
        return $this->body;
    }
}
