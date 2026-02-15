<?php

declare(strict_types=1);

namespace App\Tests\Unit\Images\Domain;

use App\Images\Domain\Exiftool;
use App\Tests\Shared\StubPlatform;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function chmod;
use function count;
use function explode;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function mkdir;
use function rmdir;
use function scandir;
use function sprintf;
use function str_starts_with;
use function sys_get_temp_dir;
use function trim;
use function uniqid;
use function unlink;
use function var_export;

final class ExiftoolTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/gallinor-exiftool-test-' . uniqid('', true);
        mkdir($this->tmpDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $entries = scandir($this->tmpDir);
        if ($entries !== false) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                unlink($this->tmpDir . '/' . $entry);
            }
        }

        rmdir($this->tmpDir);

        parent::tearDown();
    }

    public function test_force_orientation_retries_once_for_transient_write_failure(): void
    {
        $statePath   = $this->tmpDir . '/transient-state.txt';
        $counterPath = $this->tmpDir . '/attempt-count.txt';
        $toolPath    = $this->createFakeExiftool(
            'fake-exiftool-transient.php',
            sprintf(
                <<<'PHP'
#!/usr/bin/env php
<?php
$statePath = %s;
$counterPath = %s;
$target = $argv[count($argv) - 1];

$count = file_exists($counterPath) ? (int) trim((string) file_get_contents($counterPath)) : 0;
file_put_contents($counterPath, (string) ($count + 1));

if (! in_array('-Orientation=1', $argv, true)) {
    fwrite(STDOUT, "    1 image files updated\n");
    exit(0);
}

if (! in_array('-m', $argv, true)) {
    fwrite(STDERR, "Error: missing -m\n");
    exit(1);
}

if (! file_exists($statePath)) {
    file_put_contents($statePath, 'retry-once');
    fwrite(STDOUT, "    0 image files updated\n    1 files weren't updated due to errors\n");
    fwrite(STDERR, "Error renaming temporary file to " . $target . "\n");
    exit(1);
}

fwrite(STDOUT, "    1 image files updated\n");
PHP,
                var_export($statePath, true),
                var_export($counterPath, true),
            ),
        );

        $platform = new StubPlatform();
        $platform->setTool('exiftool', $toolPath);

        $exiftool = new Exiftool($platform);
        $exiftool->forceOrientationTo1('/tmp/target.heic');

        self::assertTrue(file_exists($statePath));
        self::assertSame('2', trim((string) file_get_contents($counterPath)));
    }

    public function test_force_orientation_failure_includes_exiftool_stderr(): void
    {
        $toolPath = $this->createFakeExiftool(
            'fake-exiftool-fail.php',
            <<<'PHP'
#!/usr/bin/env php
<?php
$target = $argv[count($argv) - 1];
fwrite(STDOUT, "    0 image files updated\n    1 files weren't updated due to errors\n");
fwrite(STDERR, "Error: Not a valid HEIC - " . $target . "\n");
exit(1);
PHP,
        );

        $platform = new StubPlatform();
        $platform->setTool('exiftool', $toolPath);

        $exiftool = new Exiftool($platform);

        $target = '/tmp/invalid.heic';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(sprintf(
            'exiftool failed to set Orientation=1 for %s: Error: Not a valid HEIC - %s',
            $target,
            $target,
        ));

        $exiftool->forceOrientationTo1($target);
    }

    public function test_copy_all_metadata_uses_utf8_filename_charset_on_windows_for_utf8_paths(): void
    {
        $toolPath = $this->createFakeExiftool(
            'fake-exiftool-copy-charset.php',
            <<<'PHP'
#!/usr/bin/env php
<?php
$charsetIndex = array_search('-charset', $argv, true);
$charsetValue = $charsetIndex === false ? null : ($argv[$charsetIndex + 1] ?? null);
if ($charsetValue !== 'filename=UTF8') {
    fwrite(STDERR, "Error: missing filename charset\n");
    exit(1);
}

fwrite(STDOUT, "    1 image files updated\n");
PHP,
        );

        $platform            = new StubPlatform();
        $platform->isWindows = true;
        $platform->setTool('exiftool', $toolPath);

        $exiftool = new Exiftool($platform);
        $exiftool->copyAllMetadata(
            '/tmp/Laucu — avif.avif',
            '/tmp/Laucu — heic.heic',
        );

        self::assertTrue(true);
    }

    public function test_copy_all_metadata_skips_utf8_filename_charset_on_windows_for_non_utf8_paths(): void
    {
        $toolPath = $this->createFakeExiftool(
            'fake-exiftool-copy-no-charset.php',
            <<<'PHP'
#!/usr/bin/env php
<?php
$charsetIndex = array_search('-charset', $argv, true);
$charsetValue = $charsetIndex === false ? null : ($argv[$charsetIndex + 1] ?? null);
if ($charsetValue === 'filename=UTF8') {
    fwrite(STDERR, "Error: unexpected filename charset\n");
    exit(1);
}

fwrite(STDOUT, "    1 image files updated\n");
PHP,
        );

        $platform            = new StubPlatform();
        $platform->isWindows = true;
        $platform->setTool('exiftool', $toolPath);

        $exiftool = new Exiftool($platform);
        $exiftool->copyAllMetadata(
            "/tmp/Laucu\x96avif.avif",
            "/tmp/Laucu\x96heic.heic",
        );

        self::assertTrue(true);
    }

    public function test_copy_all_metadata_normalizes_windows_path_separators(): void
    {
        $toolPath = $this->createFakeExiftool(
            'fake-exiftool-copy-separators.php',
            <<<'PHP'
#!/usr/bin/env php
<?php
$fromIndex = array_search('-tagsFromFile', $argv, true);
$fromPath = $fromIndex === false ? null : ($argv[$fromIndex + 1] ?? null);
$toPath = $argv[count($argv) - 1] ?? null;

if (! is_string($fromPath) || ! is_string($toPath)) {
    fwrite(STDERR, "Error: missing copy paths\n");
    exit(1);
}

if (str_contains($fromPath, '/') || str_contains($toPath, '/')) {
    fwrite(STDERR, "Error: path separators were not normalized\n");
    exit(1);
}

fwrite(STDOUT, "    1 image files updated\n");
PHP,
        );

        $platform            = new StubPlatform();
        $platform->isWindows = true;
        $platform->setTool('exiftool', $toolPath);

        $exiftool = new Exiftool($platform);
        $exiftool->copyAllMetadata(
            'C:/Users/andre/OneDrive/Pictures/source.avif',
            'C:/Users/andre/OneDrive/Pictures/target.heic',
        );

        self::assertTrue(true);
    }

    public function test_restore_critical_capture_metadata_copies_expected_tags(): void
    {
        $logPath  = $this->tmpDir . '/restore-critical-commands.log';
        $toolPath = $this->createFakeExiftool(
            'fake-exiftool-restore-critical.php',
            sprintf(
                <<<'PHP'
#!/usr/bin/env php
<?php
$logPath = %s;
file_put_contents($logPath, json_encode($argv) . "\n", FILE_APPEND);

if (in_array('-json', $argv, true)) {
    // Simulate short-name JSON keys (no group prefix) to ensure parser fallback works.
    fwrite(STDOUT, '[{"GPSLatitude":56.9596278,"GPSLongitude":24.1127222,"GPSAltitude":12.34}]');
    exit(0);
}

fwrite(STDOUT, "    1 image files updated\n");
PHP,
                var_export($logPath, true),
            ),
        );

        $platform = new StubPlatform();
        $platform->setTool('exiftool', $toolPath);

        $exiftool = new Exiftool($platform);
        $exiftool->restoreCriticalCaptureMetadata('/tmp/source.jpg', '/tmp/target.heic');

        $lines = explode("\n", trim((string) file_get_contents($logPath)));
        self::assertTrue(count($lines) >= 3);

        $commands = [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);
            self::assertTrue(is_array($decoded));
            $commands[] = $decoded;
        }

        $foundCriticalCopy         = false;
        $foundColorSpaceProjection = false;
        $foundQuickTime            = false;
        $foundKeys                 = false;
        $foundXmpGps               = false;

        foreach ($commands as $command) {
            if (! is_array($command)) {
                continue;
            }

            if (
                in_array('-tagsFromFile', $command, true)
                && in_array('-GPS:GPSLatitudeRef', $command, true)
                && in_array('-GPS:GPSLatitude', $command, true)
                && in_array('-GPS:GPSLongitudeRef', $command, true)
                && in_array('-GPS:GPSLongitude', $command, true)
                && in_array('-GPS:GPSAltitude', $command, true)
                && in_array('-ExifIFD:ColorSpace', $command, true)
            ) {
                $foundCriticalCopy = true;
            }

            if (in_array('-XMP-exif:ColorSpace<ExifIFD:ColorSpace', $command, true)) {
                $foundColorSpaceProjection = true;
            }

            foreach ($command as $argument) {
                if (! is_string($argument)) {
                    continue;
                }

                if (str_starts_with($argument, '-QuickTime:GPSCoordinates=')) {
                    $foundQuickTime = true;
                }

                if (str_starts_with($argument, '-Keys:GPSCoordinates=')) {
                    $foundKeys = true;
                }

                if (! str_starts_with($argument, '-XMP-exif:GPSLatitude=')) {
                    continue;
                }

                $foundXmpGps = true;
            }
        }

        self::assertTrue($foundCriticalCopy);
        self::assertTrue($foundColorSpaceProjection);
        self::assertTrue($foundQuickTime);
        self::assertTrue($foundKeys);
        self::assertTrue($foundXmpGps);
    }

    public function test_metadata_map_uses_minor_error_mode_and_returns_json_map(): void
    {
        $toolPath = $this->createFakeExiftool(
            'fake-exiftool-metadata.php',
            <<<'PHP'
#!/usr/bin/env php
<?php
if (in_array('-json', $argv, true)) {
    if (! in_array('-m', $argv, true)) {
        fwrite(STDERR, "Error: missing -m\n");
        exit(1);
    }

    fwrite(STDOUT, '[{"SourceFile":"/tmp/source.heic","EXIF:Make":"samsung"}]');
    exit(0);
}

fwrite(STDOUT, "    1 image files updated\n");
PHP,
        );

        $platform = new StubPlatform();
        $platform->setTool('exiftool', $toolPath);

        $exiftool = new Exiftool($platform);
        $map      = $exiftool->metadataMap('/tmp/source.heic');

        self::assertTrue(is_array($map));
        self::assertSame('/tmp/source.heic', $map['SourceFile']);
        self::assertSame('samsung', $map['EXIF:Make']);
    }

    public function test_orientation_uses_minor_error_mode_for_vendor_warning_tolerance(): void
    {
        $toolPath = $this->createFakeExiftool(
            'fake-exiftool-orientation.php',
            <<<'PHP'
#!/usr/bin/env php
<?php
if (in_array('-Orientation#', $argv, true)) {
    if (! in_array('-m', $argv, true)) {
        fwrite(STDERR, "Error: missing -m\n");
        exit(1);
    }

    fwrite(STDOUT, "6\n");
    exit(0);
}

fwrite(STDOUT, "    1 image files updated\n");
PHP,
        );

        $platform = new StubPlatform();
        $platform->setTool('exiftool', $toolPath);

        $exiftool = new Exiftool($platform);

        self::assertSame(6, $exiftool->orientation('/tmp/source.heic'));
    }

    private function createFakeExiftool(string $name, string $body): string
    {
        $path = $this->tmpDir . '/' . $name;
        file_put_contents($path, $body);
        chmod($path, 0o755);

        return $path;
    }
}
