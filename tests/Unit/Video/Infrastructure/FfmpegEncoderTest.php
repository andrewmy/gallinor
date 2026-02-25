<?php

declare(strict_types=1);

namespace App\Tests\Unit\Video\Infrastructure;

use App\Tests\Shared\StubPlatform;
use App\Video\Infrastructure\FfmpegEncoder;
use PHPUnit\Framework\TestCase;

use function chmod;
use function file_put_contents;
use function mkdir;
use function rmdir;
use function scandir;
use function sprintf;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use function var_export;

final class FfmpegEncoderTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/gallinor-ffmpeg-encoder-test-' . uniqid('', true);
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

    public function test_constructor_does_not_treat_vmafmotion_as_libvmaf(): void
    {
        $ffmpegPath = $this->createFakeFfmpeg(
            <<<'TXT'
Filters:
  T.. = Timeline support
  .S. = Slice threading
 .. vmafmotion        V->V       Calculate the VMAF Motion score.
TXT,
        );

        $encoder = new FfmpegEncoder(
            useCpu: true,
            platform: self::platformWithTools($ffmpegPath),
        );

        self::assertFalse($encoder->hasVmaf);
    }

    public function test_constructor_detects_libvmaf_filter(): void
    {
        $ffmpegPath = $this->createFakeFfmpeg(
            <<<'TXT'
Filters:
  T.. = Timeline support
  .S. = Slice threading
 .. libvmaf           VV->V      Calculate the VMAF score.
TXT,
        );

        $encoder = new FfmpegEncoder(
            useCpu: true,
            platform: self::platformWithTools($ffmpegPath),
        );

        self::assertTrue($encoder->hasVmaf);
    }

    private static function platformWithTools(string $ffmpegPath): StubPlatform
    {
        $platform = new StubPlatform();
        $platform->setTool('ffmpeg', $ffmpegPath);
        $platform->setTool('ffprobe', $ffmpegPath);

        return $platform;
    }

    private function createFakeFfmpeg(string $filtersOutput): string
    {
        $path = $this->tmpDir . '/fake-ffmpeg.php';
        file_put_contents(
            $path,
            sprintf(
                <<<'PHP'
#!/usr/bin/env php
<?php
$filtersOutput = %s;

if (in_array('-encoders', $argv, true)) {
    echo "Encoders:\n";
    exit(0);
}

if (in_array('-filters', $argv, true)) {
    echo $filtersOutput;
    exit(0);
}

exit(0);
PHP,
                var_export($filtersOutput, true),
            ),
        );
        chmod($path, 0o755);

        return $path;
    }
}
