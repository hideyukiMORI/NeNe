<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\EnvLoader;
use PHPUnit\Framework\TestCase;

final class EnvLoaderTest extends TestCase
{
    private const TEST_ENV_NAME = 'NENE_ENV_LOADER_TEST_VALUE';

    protected function tearDown(): void
    {
        putenv(self::TEST_ENV_NAME);
        unset($_ENV[self::TEST_ENV_NAME]);
    }

    public function testLoadIfExistsReadsSimpleEnvFile(): void
    {
        $path = $this->writeTempEnvFile(self::TEST_ENV_NAME . '=from-file');

        $loaded = EnvLoader::loadIfExists($path);

        self::assertSame(['NENE_ENV_LOADER_TEST_VALUE' => 'from-file'], $loaded);
        self::assertSame('from-file', getenv(self::TEST_ENV_NAME));
    }

    public function testExistingProcessEnvironmentWinsOverFileValue(): void
    {
        putenv(self::TEST_ENV_NAME . '=from-process');
        $_ENV[self::TEST_ENV_NAME] = 'from-process';
        $path = $this->writeTempEnvFile(self::TEST_ENV_NAME . '=from-file');

        $loaded = EnvLoader::loadIfExists($path);

        self::assertSame([], $loaded);
        self::assertSame('from-process', getenv(self::TEST_ENV_NAME));
    }

    /**
     * @return string Temporary env file path.
     */
    private function writeTempEnvFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'nene-env-');
        self::assertIsString($path);
        file_put_contents($path, $contents . PHP_EOL);
        return $path;
    }
}
