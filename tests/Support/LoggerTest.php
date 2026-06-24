<?php
declare(strict_types=1);

namespace Tds\ApiGateway\Tests\Support;

use PHPUnit\Framework\TestCase;
use Tds\ApiGateway\Support\Logger;

final class LoggerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/tds-gw-log-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            foreach ((array) glob($this->dir . '/*') as $f) {
                @unlink((string) $f);
            }
            @rmdir($this->dir);
        }
    }

    public function testWritesJsonLineWithLevelMessageAndContext(): void
    {
        $path = $this->dir . '/gateway.log';
        $logger = new Logger($path, 'info');

        $logger->error('upstream request failed', [
            'service' => '/content',
            'curl_errno' => 7,
            'target' => 'http://127.0.0.1:8001/blog',
        ]);

        self::assertFileExists($path);
        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($path))));
        self::assertCount(1, $lines);

        $entry = json_decode($lines[0], true);
        self::assertSame('error', $entry['level']);
        self::assertSame('upstream request failed', $entry['msg']);
        self::assertSame('/content', $entry['service']);
        self::assertSame(7, $entry['curl_errno']);
        self::assertArrayHasKey('ts', $entry);
    }

    public function testCreatesMissingDirectoryAndAppends(): void
    {
        $path = $this->dir . '/nested/deep/gateway.log';
        $logger = new Logger($path, 'debug');

        $logger->info('one');
        $logger->info('two');

        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($path))));
        self::assertCount(2, $lines);
    }

    public function testRespectsLevelThreshold(): void
    {
        $path = $this->dir . '/gateway.log';
        $logger = new Logger($path, 'warning');

        $logger->info('ignored below threshold');
        $logger->debug('also ignored');
        $logger->warning('kept');
        $logger->error('kept too');

        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($path))));
        self::assertCount(2, $lines);
        self::assertStringContainsString('"kept"', $lines[0]);
    }

    public function testOffLevelIsANoOpAndWritesNothing(): void
    {
        $path = $this->dir . '/gateway.log';
        $logger = new Logger($path, 'off');
        $logger->error('should not be written');

        self::assertFileDoesNotExist($path);
    }

    public function testOffPathIsANoOp(): void
    {
        $logger = new Logger('off', 'debug');
        $logger->error('nowhere');
        // No file, no exception — disabled by the `off` sentinel path.
        self::assertDirectoryDoesNotExist($this->dir);
    }

    public function testFromEnvResolvesRelativePathUnderRoot(): void
    {
        $root = $this->dir;
        @mkdir($root, 0775, true);
        $env = static function (string $key, ?string $default = null) use ($root): string {
            return match ($key) {
                'GATEWAY_LOG_FILE' => 'logs/gateway.log',
                'GATEWAY_LOG_LEVEL' => 'info',
                default => (string) $default,
            };
        };

        $logger = Logger::fromEnv($env, $root);
        $logger->info('hello');

        self::assertFileExists($root . '/logs/gateway.log');
    }
}
