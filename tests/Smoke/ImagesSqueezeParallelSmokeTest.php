<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

use function dirname;
use function fclose;
use function file_exists;
use function implode;
use function is_dir;
use function mkdir;
use function rmdir;
use function scandir;
use function str_contains;
use function stream_socket_server;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const PHP_BINARY;

final class ImagesSqueezeParallelSmokeTest extends TestCase
{
    public function test_help_lists_parallel_options(): void
    {
        $process = self::runApp(['help', 'images:squeeze']);

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
                'images:squeeze',
                '--dry-run',
                '--parallel',
                '--concurrency=2',
                '--worker-max-jobs=2',
                '--job-timeout=5',
                $tempDir,
            ]);

            self::assertSuccessfulOrSkipForEnvironment($process);

            $output = $process->getOutput() . $process->getErrorOutput();
            self::assertMatchesRegularExpression(
                '/Parallel JPEG mode: enabled \((?:fixed(?: safe)? workers=2|adaptive start-workers=\d+, max-workers=2), worker-max-jobs=2, job-timeout=5s\)/',
                $output,
            );
            self::assertStringContainsString('Dry run complete. Found 0 JPEGs to process, 0 ARW directories to archive.', $output);
        } finally {
            self::removeDir($tempDir);
        }
    }

    public function test_parallel_smoke_run_on_tiny_jpeg_reports_no_errors(): void
    {
        if (! self::canBindLoopbackSocket()) {
            self::markTestSkipped('Loopback socket bind is not permitted in this environment.');
        }

        $tempDir  = self::createTempDir();
        $jpegPath = $tempDir . '/smoke.jpg';

        try {
            $ffmpeg = new Process([
                'ffmpeg',
                '-hide_banner',
                '-loglevel',
                'error',
                '-y',
                '-f',
                'lavfi',
                '-i',
                'color=c=white:s=16x16:d=1',
                '-frames:v',
                '1',
                $jpegPath,
            ], self::projectRoot());
            $ffmpeg->setTimeout(30);
            $ffmpeg->run();

            if ($ffmpeg->getExitCode() !== 0) {
                $details = $ffmpeg->getOutput() . $ffmpeg->getErrorOutput();
                if (str_contains($details, 'command not found') || str_contains($details, 'No such file or directory')) {
                    self::markTestSkipped('ffmpeg is not available for smoke generation.');
                }

                self::fail(self::processDebugMessage($ffmpeg));
            }

            self::assertFileExists($jpegPath);

            $process = self::runApp([
                'images:squeeze',
                '--parallel',
                '--concurrency=1',
                '--worker-max-jobs=1',
                '--job-timeout=60',
                $tempDir,
            ], 300);

            self::assertSuccessfulOrSkipForEnvironment($process);

            $output = $process->getOutput() . $process->getErrorOutput();

            self::assertMatchesRegularExpression(
                '/Parallel JPEG mode: enabled \((?:fixed(?: safe)? workers=1|adaptive start-workers=\d+, max-workers=1), worker-max-jobs=1, job-timeout=60s\)/',
                $output,
            );
            self::assertStringContainsString('JPEG Summary:', $output);
            self::assertStringContainsString('  Found: 1', $output);
            self::assertStringContainsString('  Errored: 0', $output);
            self::assertMatchesRegularExpression('/\n\s+(Processed|Skipped): 1\b/', $output);
            self::assertTrue(file_exists($jpegPath));
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
            self::markTestSkipped('Image toolchain is not available in this environment.');
        }

        if (str_contains($output, 'Failed to start worker server: Operation not permitted')) {
            self::markTestSkipped('Worker IPC socket binding is not permitted in this environment.');
        }

        self::fail(self::processDebugMessage($process));
    }

    private static function canBindLoopbackSocket(): bool
    {
        $server = @stream_socket_server('tcp://127.0.0.1:0');
        if ($server === false) {
            return false;
        }

        fclose($server);

        return true;
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
