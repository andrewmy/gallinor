<?php

declare(strict_types=1);

namespace App\Tests\Unit\Images\Domain;

use App\Images\Domain\Exiftool;
use App\Tests\Shared\StubPlatform;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function chmod;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function mkdir;
use function rmdir;
use function scandir;
use function sprintf;
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

    private function createFakeExiftool(string $name, string $body): string
    {
        $path = $this->tmpDir . '/' . $name;
        file_put_contents($path, $body);
        chmod($path, 0o755);

        return $path;
    }
}
