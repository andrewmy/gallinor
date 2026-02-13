<?php

declare(strict_types=1);

namespace App\Tests\Unit\Images\Domain;

use App\Images\Domain\ExifMetadata;
use App\Images\Domain\FfmpegImageNormalizer;
use App\Tests\Shared\StubPlatform;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_search;
use function chmod;
use function count;
use function file_get_contents;
use function file_put_contents;
use function is_array;
use function is_string;
use function json_decode;
use function mkdir;
use function rmdir;
use function scandir;
use function sprintf;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use function var_export;

final class FfmpegImageNormalizerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/gallinor-ffmpeg-normalizer-test-' . uniqid('', true);
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

    public function test_jpeg_normalization_disables_ffmpeg_autorotate_and_applies_orientation_filter(): void
    {
        $argvLogPath = $this->tmpDir . '/argv-log.json';
        $ffmpegPath  = $this->createFakeFfmpeg('fake-ffmpeg-rotate.php', $argvLogPath);

        $platform = new StubPlatform();
        $platform->setTool('ffmpeg', $ffmpegPath);

        $normalizer = new FfmpegImageNormalizer(
            $platform,
            self::exifMetadataWithOrientation(6),
        );

        $normalizer->jpegToUprightPng('/tmp/source.jpg', $this->tmpDir . '/target.png');

        $argv = self::readLoggedArgv($argvLogPath);

        self::assertContains('-noautorotate', $argv);

        $vfIndex = array_search('-vf', $argv, true);
        self::assertNotFalse($vfIndex);
        self::assertArrayHasKey($vfIndex + 1, $argv);
        self::assertSame('transpose=1', $argv[$vfIndex + 1]);
    }

    public function test_orientation_1_does_not_add_rotation_filter(): void
    {
        $argvLogPath = $this->tmpDir . '/argv-log-orientation-1.json';
        $ffmpegPath  = $this->createFakeFfmpeg('fake-ffmpeg-orientation-1.php', $argvLogPath);

        $platform = new StubPlatform();
        $platform->setTool('ffmpeg', $ffmpegPath);

        $normalizer = new FfmpegImageNormalizer(
            $platform,
            self::exifMetadataWithOrientation(1),
        );

        $normalizer->imageToUprightPngWithOrientation('/tmp/source.jpg', $this->tmpDir . '/target-1.png', 1);

        $argv = self::readLoggedArgv($argvLogPath);

        self::assertContains('-noautorotate', $argv);
        self::assertNotContains('-vf', $argv);
    }

    /** @return list<string> */
    private static function readLoggedArgv(string $argvLogPath): array
    {
        $decoded = json_decode((string) file_get_contents($argvLogPath), true);

        self::assertTrue(is_array($decoded));
        self::assertNotSame([], $decoded);
        self::assertSame(count($decoded), count(array_filter($decoded, static fn (mixed $value): bool => is_string($value))));

        /** @var list<string> $decodedStrings */
        $decodedStrings = $decoded;

        return $decodedStrings;
    }

    private function createFakeFfmpeg(string $name, string $argvLogPath): string
    {
        $path = $this->tmpDir . '/' . $name;
        file_put_contents(
            $path,
            sprintf(
                <<<'PHP'
#!/usr/bin/env php
<?php
$logPath = %s;

if (! in_array('-noautorotate', $argv, true)) {
    fwrite(STDERR, "missing -noautorotate\n");
    exit(1);
}

file_put_contents($logPath, json_encode($argv));
$target = $argv[count($argv) - 1];
file_put_contents($target, "ok");
PHP,
                var_export($argvLogPath, true),
            ),
        );
        chmod($path, 0o755);

        return $path;
    }

    private static function exifMetadataWithOrientation(int $orientation): ExifMetadata
    {
        return new readonly class ($orientation) implements ExifMetadata {
            public function __construct(private int $orientation)
            {
            }

            public function orientation(string $path): int
            {
                return $this->orientation;
            }

            public function copyAllMetadata(string $from, string $to): void
            {
            }

            public function forceOrientationTo1(string $path): void
            {
            }

            public function deleteDerivedDimensionTags(string $path): void
            {
            }

            /** @return array<string, string> */
            public function metadataMap(string $path): array
            {
                return [];
            }

            /** @return array<string, true> */
            public function findPortraitAndLivePhotos(string $dir): array
            {
                return [];
            }
        };
    }
}
