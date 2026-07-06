<?php
declare(strict_types=1);

namespace Tds\ApiGateway\Tests\Support;

use PHPUnit\Framework\TestCase;
use Tds\ApiGateway\Support\MigrationRunner;

final class MigrationRunnerTest extends TestCase
{
    private string $base;
    private string $servicesDir;
    private string $stateDir;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/tds-gw-mig-' . bin2hex(random_bytes(4));
        $this->servicesDir = $this->base . '/services';
        $this->stateDir = $this->base . '/var';
        // Two fake services, each with one migration file.
        foreach (['auth', 'content'] as $name) {
            @mkdir($this->servicesDir . '/' . $name . '/db/migrations', 0775, true);
            file_put_contents(
                $this->servicesDir . '/' . $name . '/db/migrations/20260101000001_init.php',
                "<?php // fake\n",
            );
        }
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->base);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach ((array) scandir($dir) as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $p = $dir . '/' . $e;
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    /** @param list<string> $calls */
    private function runner(array &$calls, bool $succeed = true): MigrationRunner
    {
        return new MigrationRunner(
            servicesDir: $this->servicesDir,
            serviceNames: ['auth', 'content'],
            stateDir: $this->stateDir,
            logger: null,
            migrate: function (string $dir) use (&$calls, $succeed): array {
                $calls[] = basename($dir);
                return [$succeed, $succeed ? 'ok' : 'boom'];
            },
        );
    }

    public function testMigratesEveryServiceThenWritesMarker(): void
    {
        $calls = [];
        $this->runner($calls)->ensureMigrated();

        self::assertSame(['auth', 'content'], $calls);
        self::assertNotEmpty(glob($this->stateDir . '/.migrated-*'), 'marker should be written');
    }

    public function testSecondRunIsANoOpOnceMarked(): void
    {
        $calls = [];
        $this->runner($calls)->ensureMigrated();
        // Second runner instance, same migration-set → marker short-circuits.
        $calls2 = [];
        $this->runner($calls2)->ensureMigrated();

        self::assertSame(['auth', 'content'], $calls);
        self::assertSame([], $calls2, 'already-migrated set must not re-run');
    }

    public function testFailureDoesNotWriteMarkerSoItRetries(): void
    {
        $calls = [];
        $this->runner($calls, succeed: false)->ensureMigrated();
        self::assertSame([], glob($this->stateDir . '/.migrated-*') ?: [], 'no marker on failure');

        // A subsequent run retries (still no marker latched).
        $calls2 = [];
        $this->runner($calls2, succeed: false)->ensureMigrated();
        self::assertSame(['auth', 'content'], $calls2, 'failed set must retry');
    }

    public function testAddingAMigrationChangesTheSignatureAndReRuns(): void
    {
        $calls = [];
        $this->runner($calls)->ensureMigrated();
        self::assertSame(['auth', 'content'], $calls);

        // Ship a new migration → new signature → new marker → re-run.
        file_put_contents(
            $this->servicesDir . '/content/db/migrations/20260202000002_more.php',
            "<?php // fake 2\n",
        );
        $calls2 = [];
        $this->runner($calls2)->ensureMigrated();
        self::assertSame(['auth', 'content'], $calls2, 'a new migration must trigger a re-run');
    }

    public function testSkipsServicesMissingFromTheBundle(): void
    {
        $calls = [];
        $runner = new MigrationRunner(
            servicesDir: $this->servicesDir,
            serviceNames: ['auth', 'content', 'customer'], // customer dir doesn't exist
            stateDir: $this->stateDir,
            logger: null,
            migrate: function (string $dir) use (&$calls): array {
                $calls[] = basename($dir);
                return [true, 'ok'];
            },
        );
        $runner->ensureMigrated();

        self::assertSame(['auth', 'content'], $calls, 'absent service dir is skipped, not failed');
    }

    public function testSkipsAServiceWhoseMigrationClassNameCollides(): void
    {
        // Two services shipping the same class name would be an uncatchable
        // fatal when Phinx includes both into the one in-process PHP process
        // (the CreateAppSetting outage) — the later service must be skipped.
        file_put_contents(
            $this->servicesDir . '/auth/db/migrations/20260705000001_create_app_setting.php',
            "<?php\nfinal class CreateAppSetting extends AbstractMigration {}\n",
        );
        file_put_contents(
            $this->servicesDir . '/content/db/migrations/20260705000001_create_app_setting.php',
            "<?php\nfinal class CreateAppSetting extends AbstractMigration {}\n",
        );

        $calls = [];
        $this->runner($calls)->ensureMigrated();

        self::assertSame(['auth'], $calls, 'colliding service must be skipped, not included');
        self::assertSame([], glob($this->stateDir . '/.migrated-*') ?: [], 'a skipped service must not latch the marker');
    }

    public function testUniqueMigrationClassNamesAcrossServicesAllRun(): void
    {
        file_put_contents(
            $this->servicesDir . '/auth/db/migrations/20260705000001_create_auth_app_setting.php',
            "<?php\nfinal class CreateAuthAppSetting extends AbstractMigration {}\n",
        );
        file_put_contents(
            $this->servicesDir . '/content/db/migrations/20260705000001_create_content_app_setting.php',
            "<?php\nfinal class CreateContentAppSetting extends AbstractMigration {}\n",
        );

        $calls = [];
        $this->runner($calls)->ensureMigrated();

        self::assertSame(['auth', 'content'], $calls);
        self::assertNotEmpty(glob($this->stateDir . '/.migrated-*'), 'unique class names migrate normally');
    }

    public function testPhpCliBinaryNeverReturnsAnFpmBinary(): void
    {
        $bin = MigrationRunner::phpCliBinary();
        self::assertNotEmpty($bin);
        self::assertStringNotContainsStringIgnoringCase('fpm', $bin);
    }
}
