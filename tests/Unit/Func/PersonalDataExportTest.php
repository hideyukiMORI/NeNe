<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Func;

use Nene\Func\PersonalDataExport;
use PHPUnit\Framework\TestCase;

final class PersonalDataExportTest extends TestCase
{
    // --- export structure ---

    public function testExportContainsTopLevelKeys(): void
    {
        $export = new PersonalDataExport();
        $result = $export->export(1);

        self::assertArrayHasKey('exportedAt', $result);
        self::assertArrayHasKey('userId', $result);
        self::assertArrayHasKey('data', $result);
    }

    public function testExportedAtIsIso8601(): void
    {
        $export = new PersonalDataExport();
        $result = $export->export(1);

        // DateTimeInterface::ATOM produces e.g. 2026-05-27T12:00:00+00:00
        $dt = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $result['exportedAt']);
        self::assertNotFalse($dt, 'exportedAt must be ATOM format');
    }

    public function testUserIdPreservedAsInteger(): void
    {
        $export = new PersonalDataExport();
        self::assertSame(42, $export->export(42)['userId']);
    }

    public function testUserIdPreservedAsString(): void
    {
        $export = new PersonalDataExport();
        self::assertSame('uuid-abc', $export->export('uuid-abc')['userId']);
    }

    // --- no providers ---

    public function testExportWithNoProvidersReturnsEmptyData(): void
    {
        $export = new PersonalDataExport();
        self::assertSame([], $export->export(1)['data']);
    }

    // --- single provider ---

    public function testSingleProviderSectionPresent(): void
    {
        $export = new PersonalDataExport();
        $export->register('profile', function (int|string $id): array {
            return ['name' => 'Taro', 'userId' => $id];
        });

        $data = $export->export(7)['data'];

        self::assertArrayHasKey('profile', $data);
        self::assertSame('Taro', $data['profile']['name']);
        self::assertSame(7, $data['profile']['userId']);
    }

    // --- multiple providers ---

    public function testMultipleProvidersSectionsAllPresent(): void
    {
        $export = new PersonalDataExport();
        $export->register('profile', fn (int|string $id): array => ['id' => $id]);
        $export->register('orders', fn (int|string $id): array => [['orderId' => 1]]);
        $export->register('activity', fn (int|string $id): array => []);

        $data = $export->export(99)['data'];

        self::assertArrayHasKey('profile', $data);
        self::assertArrayHasKey('orders', $data);
        self::assertArrayHasKey('activity', $data);
    }

    public function testSectionsAppearInRegistrationOrder(): void
    {
        $export = new PersonalDataExport();
        $export->register('c', fn (int|string $id): array => []);
        $export->register('a', fn (int|string $id): array => []);
        $export->register('b', fn (int|string $id): array => []);

        self::assertSame(['c', 'a', 'b'], array_keys($export->export(1)['data']));
    }

    // --- provider receives userId ---

    public function testProviderReceivesCorrectUserId(): void
    {
        $captured = null;
        $export   = new PersonalDataExport();
        $export->register('test', function (int|string $id) use (&$captured): array {
            $captured = $id;
            return [];
        });

        $export->export(123);

        self::assertSame(123, $captured);
    }

    // --- overwrite ---

    public function testRegisteringAgainOverwritesPreviousProvider(): void
    {
        $export = new PersonalDataExport();
        $export->register('section', fn (int|string $id): array => ['version' => 1]);
        $export->register('section', fn (int|string $id): array => ['version' => 2]);

        $data = $export->export(1)['data'];

        self::assertSame(2, $data['section']['version']);
        self::assertCount(1, $data); // still one section
    }

    // --- fluent register ---

    public function testRegisterReturnsSelf(): void
    {
        $export = new PersonalDataExport();
        $result = $export->register('x', fn (int|string $id): array => []);
        self::assertSame($export, $result);
    }

    public function testFluentChaining(): void
    {
        $export = (new PersonalDataExport())
            ->register('a', fn (int|string $id): array => ['a'])
            ->register('b', fn (int|string $id): array => ['b']);

        self::assertSame(['a', 'b'], $export->sections());
    }

    // --- sections() ---

    public function testSectionsReturnsRegisteredNames(): void
    {
        $export = new PersonalDataExport();
        $export->register('profile', fn (int|string $id): array => []);
        $export->register('orders', fn (int|string $id): array => []);

        self::assertSame(['profile', 'orders'], $export->sections());
    }

    public function testSectionsEmptyWhenNoProviders(): void
    {
        self::assertSame([], (new PersonalDataExport())->sections());
    }

    // --- exportJson ---

    public function testExportJsonReturnsValidJson(): void
    {
        $export = new PersonalDataExport();
        $export->register('profile', fn (int|string $id): array => ['name' => 'Taro']);

        $json   = $export->exportJson(1);
        $decoded = json_decode($json, true);

        self::assertIsArray($decoded);
        self::assertArrayHasKey('exportedAt', $decoded);
        self::assertArrayHasKey('userId', $decoded);
        self::assertArrayHasKey('data', $decoded);
    }

    public function testExportJsonContainsProviderData(): void
    {
        $export = new PersonalDataExport();
        $export->register('profile', fn (int|string $id): array => ['name' => '太郎']);

        $json    = $export->exportJson(1);
        $decoded = json_decode($json, true);

        // JSON_UNESCAPED_UNICODE — Japanese must not be escaped
        self::assertStringContainsString('太郎', $json);
        self::assertSame('太郎', $decoded['data']['profile']['name']);
    }

    public function testExportJsonCustomFlags(): void
    {
        $export = new PersonalDataExport();
        $json   = $export->exportJson(1, 0); // compact (no pretty-print)

        // compact JSON has no newlines
        self::assertStringNotContainsString("\n", $json);
    }

    // --- provider returning empty array ---

    public function testEmptyProviderArrayIsIncludedInExport(): void
    {
        $export = new PersonalDataExport();
        $export->register('empty_section', fn (int|string $id): array => []);

        $data = $export->export(1)['data'];

        self::assertArrayHasKey('empty_section', $data);
        self::assertSame([], $data['empty_section']);
    }
}
