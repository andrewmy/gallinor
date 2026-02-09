<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

use function dirname;
use function implode;
use function is_dir;
use function mkdir;
use function rmdir;
use function scandir;
use function str_contains;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const PHP_BINARY;

final class ImagesMigrateAvifToHeicParallelSmokeTest extends TestCase
{
    public function test_help_lists_parallel_options(): void
    {
        $process = self::runApp(['help', 'images:migrate-avif-to-heic']);

        self::assertSame(0, $process->getExitCode(), self::processDebugMessage($process));

        $output = $process->getOutput() . $process->getErrorOutput();

        self::assertStringContainsString('--parallel', $output);
        self::assertStringContainsString('--concurrency=CONCURRENCY', $output);
        self::assertStringContainsString('--worker-max-jobs=WORKER-MAX-JOBS', $output);
        self::assertStringContainsString('--job-timeout=JOB-TIMEOUT', $output);
    }

    public function test_parallel_dry_run_with_flags_succeeds_on_empty_directory(): void
    {
        $tempDir = self::createTempDir();

        try {
            $process = self::runApp([
                'images:migrate-avif-to-heic',
                '--dry-run',
                '--parallel',
                '--concurrency=2',
                '--worker-max-jobs=2',
                '--job-timeout=5',
                $tempDir,
            ]);

            self::assertSuccessfulOrSkipForEnvironment($process);

            $output = $process->getOutput() . $process->getErrorOutput();
            self::assertStringContainsString('Parallel AVIF migration mode: enabled (workers=2, worker-max-jobs=2, job-timeout=5s)', $output);
            self::assertStringContainsString('Found 0 AVIFs (0 already have .heic, 0 to migrate)', $output);
        } finally {
            self::removeDir($tempDir);
        }
    }

    /** @param list<string> $args */
    private static function runApp(array $args, int $timeoutSeconds = 120): Process
    {
        $process = new Process([
            PHP_BINARY,
            'app.php',
            ...$args,
        ], self::projectRoot());
        $process->setTimeout($timeoutSeconds);
        $process->run();

        return $process;
    }

    private static function projectRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function assertSuccessfulOrSkipForEnvironment(Process $process): void
    {
        if ($process->getExitCode() === 0) {
            return;
        }

        $output = $process->getOutput() . $process->getErrorOutput();

        if (str_contains($output, 'Required tool not found:')) {
            self::markTestSkipped('Image migration toolchain is not available in this environment.');
        }

        if (str_contains($output, 'Failed to start worker server: Operation not permitted')) {
            self::markTestSkipped('Worker IPC socket binding is not permitted in this environment.');
        }

        self::fail(self::processDebugMessage($process));
    }

    private static function createTempDir(): string
    {
        $path = sys_get_temp_dir() . '/gallinor-smoke-' . uniqid('', true);
        if (! mkdir($path, 0o777, true) && ! is_dir($path)) {
            self::fail('Failed to create temporary directory for smoke test.');
        }

        return $path;
    }

    private static function removeDir(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $entries = scandir($path);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $candidate = $path . '/' . $entry;
            if (is_dir($candidate)) {
                self::removeDir($candidate);
                continue;
            }

            @unlink($candidate);
        }

        @rmdir($path);
    }

    private static function processDebugMessage(Process $process): string
    {
        return implode("\n", [
            'Command failed.',
            'Exit code: ' . (string) $process->getExitCode(),
            'STDOUT:',
            $process->getOutput(),
            'STDERR:',
            $process->getErrorOutput(),
        ]);
    }
}
