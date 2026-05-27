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

namespace Nene\Func;

/**
 * GDPR Article 20-style personal data export aggregator.
 *
 * Collects user data from multiple registered providers and assembles it into
 * a portable JSON structure. Each provider is responsible for one logical
 * "section" of the export (profile, orders, activity, etc.).
 *
 * ## Basic usage
 *
 * ```php
 * $export = new PersonalDataExport();
 *
 * $export->register('profile', function (int|string $userId) use ($pdo): array {
 *     $stmt = $pdo->prepare('SELECT name, email, created_at FROM users WHERE id = ?');
 *     $stmt->execute([$userId]);
 *     return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
 * });
 *
 * $export->register('orders', function (int|string $userId) use ($pdo): array {
 *     $stmt = $pdo->prepare('SELECT id, total, created_at FROM orders WHERE user_id = ?');
 *     $stmt->execute([$userId]);
 *     return $stmt->fetchAll(\PDO::FETCH_ASSOC);
 * });
 *
 * $json = $export->exportJson(42);
 * ```
 *
 * ## Output format
 *
 * ```json
 * {
 *   "exportedAt": "2026-05-27T12:00:00+00:00",
 *   "userId": 42,
 *   "data": {
 *     "profile": { "name": "Taro", "email": "taro@example.com" },
 *     "orders":  [{ "id": 1, "total": 1500 }]
 *   }
 * }
 * ```
 *
 * ## Design notes
 *
 * - **Instance-based**: each `PersonalDataExport` object holds its own provider
 *   registry, making it straightforward to configure per-request or per-test.
 * - **Provider signature**: `callable(int|string $userId): array<string, mixed>`.
 *   The return value is the section data; shape is entirely up to the provider.
 * - **Section ordering**: sections appear in registration order.
 * - **No database coupling**: providers capture their own DB connection via closure,
 *   keeping the aggregator free of infrastructure dependencies.
 */
final class PersonalDataExport
{
    /**
     * Registered providers keyed by section name.
     *
     * @var array<string, callable>
     */
    private array $providers = [];

    /**
     * Register a data provider for the given section.
     *
     * The provider is a callable that receives the user ID and returns an array
     * representing that section's data. Multiple registrations for the same
     * section name overwrite the previous provider.
     *
     * @param  string                           $section  Section name (e.g. 'profile', 'orders').
     * @param  callable $provider Callable that accepts a user ID and returns data.
     * @return static                                      Fluent — allows chaining.
     */
    public function register(string $section, callable $provider): static
    {
        $this->providers[$section] = $provider;
        return $this;
    }

    /**
     * Run all providers for the given user ID and return the aggregated export.
     *
     * The returned array has three top-level keys:
     *  - `exportedAt` (string) — ISO 8601 timestamp of the export.
     *  - `userId`    (int|string) — the requested user ID.
     *  - `data`      (array) — section name → section data, in registration order.
     *
     * @param  int|string $userId User identifier.
     * @return array{exportedAt: string, userId: int|string, data: array<string, array<mixed>>}
     */
    public function export(int|string $userId): array
    {
        $data = [];
        foreach ($this->providers as $section => $provider) {
            $data[$section] = $provider($userId);
        }

        return [
            'exportedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'userId'     => $userId,
            'data'       => $data,
        ];
    }

    /**
     * Run all providers and return a JSON-encoded export string.
     *
     * Default flags produce pretty-printed, Unicode-safe output.
     *
     * @param  int|string $userId User identifier.
     * @param  int        $flags  `json_encode()` flags (default: JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).
     * @return string     JSON string.
     *
     * @throws \JsonException On encoding failure (triggered by JSON_THROW_ON_ERROR).
     */
    public function exportJson(
        int|string $userId,
        int $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE,
    ): string {
        return json_encode($this->export($userId), $flags | JSON_THROW_ON_ERROR);
    }

    /**
     * Return the list of registered section names, in registration order.
     *
     * Useful in tests to verify that all expected providers are registered.
     *
     * @return list<string>
     */
    public function sections(): array
    {
        return array_keys($this->providers);
    }
}
