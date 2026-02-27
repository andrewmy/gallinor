<?php

declare(strict_types=1);

namespace App\Tests\Unit\Video\Infrastructure;

use App\Tests\Shared\StubPlatform;
use App\Video\Domain\VideoFile;
use App\Video\Infrastructure\FfmpegEncoder;
use PHPUnit\Framework\TestCase;

use function chmod;
use function count;
use function file_get_contents;
use function file_put_contents;
use function filesize;
use function is_array;
use function json_decode;
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

    public function test_quality_score_normalizes_streams_before_libvmaf(): void
    {
        $argvLogPath = $this->tmpDir . '/quality-score-argv.json';
        $ffmpegPath  = $this->createFakeFfmpegWithQualityScore($argvLogPath);

        $encoder = new FfmpegEncoder(
            useCpu: true,
            platform: self::platformWithTools($ffmpegPath),
        );

        $score = $encoder->qualityScore(
            originalFilePath: '/tmp/original.mp4',
            processedFilePath: '/tmp/processed.mp4',
        );

        self::assertSame(91.25, $score);

        $argv = self::readLoggedArgv($argvLogPath);
        self::assertSame($ffmpegPath, $argv[0]);

        $firstInput = self::indexOf($argv, '-i');
        self::assertNotNull($firstInput);
        self::assertArrayHasKey($firstInput + 1, $argv);
        self::assertSame('/tmp/original.mp4', $argv[$firstInput + 1]);

        $secondInput = self::indexOf($argv, '-i', $firstInput + 1);
        self::assertNotNull($secondInput);
        self::assertArrayHasKey($secondInput + 1, $argv);
        self::assertSame('/tmp/processed.mp4', $argv[$secondInput + 1]);

        $filterComplexIndex = self::indexOf($argv, '-filter_complex');
        self::assertNotNull($filterComplexIndex);
        self::assertArrayHasKey($filterComplexIndex + 1, $argv);

        $filter = $argv[$filterComplexIndex + 1];
        self::assertStringContainsString('[0:v]settb=AVTB,setpts=N[reference]', $filter);
        self::assertStringContainsString('[1:v]settb=AVTB,setpts=N[distorted]', $filter);
        self::assertStringContainsString('[distorted][reference]libvmaf=', $filter);
    }

    public function test_rotated_video_uses_cpu_when_active_encoder_is_apple(): void
    {
        $ffmpegPath = $this->createFakeFfmpegWithEncoders(
            "Encoders:\nV..... hevc_videotoolbox Apple VideoToolbox encoder\n",
        );

        $encoder = new FfmpegEncoder(
            useCpu: false,
            platform: self::darwinPlatformWithTools($ffmpegPath),
        );

        $command = $encoder->commandForFile(
            file: self::videoFile(hasRotation: true),
            baseBitrate: 8000,
            maxBitrateSpike: 1.25,
            tempFilePath: '/tmp/out.mp4',
        );

        self::assertStringContainsString('-c:v libx265', $command);
    }

    public function test_non_rotated_video_keeps_apple_encoder(): void
    {
        $ffmpegPath = $this->createFakeFfmpegWithEncoders(
            "Encoders:\nV..... hevc_videotoolbox Apple VideoToolbox encoder\n",
        );

        $encoder = new FfmpegEncoder(
            useCpu: false,
            platform: self::darwinPlatformWithTools($ffmpegPath),
        );

        $command = $encoder->commandForFile(
            file: self::videoFile(hasRotation: false),
            baseBitrate: 8000,
            maxBitrateSpike: 1.25,
            tempFilePath: '/tmp/out.mp4',
        );

        self::assertStringContainsString('-c:v hevc_videotoolbox', $command);
    }

    public function test_command_preserves_fps_mode_passthrough(): void
    {
        $ffmpegPath = $this->createFakeFfmpegWithEncoders(
            "Encoders:\n",
        );

        $encoder = new FfmpegEncoder(
            useCpu: true,
            platform: self::platformWithTools($ffmpegPath),
        );

        $command = $encoder->commandForFile(
            file: self::videoFile(hasRotation: false),
            baseBitrate: 8000,
            maxBitrateSpike: 1.25,
            tempFilePath: '/tmp/out.mp4',
        );

        self::assertStringContainsString('-fps_mode passthrough', $command);
    }

    public function test_nvenc_command_enables_supported_hq_options(): void
    {
        $ffmpegPath = $this->createFakeFfmpegWithEncoders(
            "Encoders:\nV..... hevc_nvenc NVIDIA NVENC hevc encoder\n",
            <<<'TXT'
Encoder hevc_nvenc [NVIDIA NVENC hevc encoder]:
  -rc                <int>
     vbr_hq          2
  -cq                <float>
  -rc-lookahead      <int>
  -multipass         <int>
  -temporal-aq       <boolean>
  -bf                <int>
  -b_ref_mode        <int>
TXT,
        );

        $encoder = new FfmpegEncoder(
            useCpu: false,
            platform: self::platformWithTools($ffmpegPath),
        );

        $command = $encoder->commandForFile(
            file: self::videoFile(hasRotation: false),
            baseBitrate: 8000,
            maxBitrateSpike: 1.25,
            tempFilePath: '/tmp/out.mp4',
        );

        self::assertStringContainsString('-c:v hevc_nvenc', $command);
        self::assertStringContainsString('-rc vbr_hq', $command);
        self::assertStringContainsString('-cq 21', $command);
        self::assertStringContainsString('-rc-lookahead 32', $command);
        self::assertStringContainsString('-multipass fullres', $command);
        self::assertStringContainsString('-temporal-aq 1', $command);
        self::assertStringContainsString('-bf 4', $command);
        self::assertStringContainsString('-b_ref_mode middle', $command);
    }

    public function test_nvenc_command_skips_unsupported_hq_options(): void
    {
        $ffmpegPath = $this->createFakeFfmpegWithEncoders(
            "Encoders:\nV..... hevc_nvenc NVIDIA NVENC hevc encoder\n",
            <<<'TXT'
Encoder hevc_nvenc [NVIDIA NVENC hevc encoder]:
  -rc                <int>
TXT,
        );

        $encoder = new FfmpegEncoder(
            useCpu: false,
            platform: self::platformWithTools($ffmpegPath),
        );

        $command = $encoder->commandForFile(
            file: self::videoFile(hasRotation: false),
            baseBitrate: 8000,
            maxBitrateSpike: 1.25,
            tempFilePath: '/tmp/out.mp4',
        );

        self::assertStringContainsString('-c:v hevc_nvenc', $command);
        self::assertStringContainsString('-rc vbr', $command);
        self::assertStringNotContainsString('-rc vbr_hq', $command);
        self::assertStringNotContainsString('-cq 21', $command);
        self::assertStringNotContainsString('-rc-lookahead 32', $command);
        self::assertStringNotContainsString('-multipass fullres', $command);
        self::assertStringNotContainsString('-temporal-aq 1', $command);
        self::assertStringNotContainsString('-bf 4', $command);
        self::assertStringNotContainsString('-b_ref_mode middle', $command);
    }

    public function test_apple_command_enables_supported_quality_options(): void
    {
        $ffmpegPath = $this->createFakeFfmpegWithEncoders(
            "Encoders:\nV..... hevc_videotoolbox Apple VideoToolbox encoder\n",
            <<<'TXT'
Encoder hevc_videotoolbox [VideoToolbox H.265 Encoder]:
  -prio_speed        <boolean>
  -realtime          <boolean>
  -spatial_aq        <int>
  -power_efficient   <int>
  -max_ref_frames    <int>
TXT,
        );

        $encoder = new FfmpegEncoder(
            useCpu: false,
            platform: self::darwinPlatformWithTools($ffmpegPath),
        );

        $command = $encoder->commandForFile(
            file: self::videoFile(hasRotation: false),
            baseBitrate: 8000,
            maxBitrateSpike: 1.25,
            tempFilePath: '/tmp/out.mp4',
        );

        self::assertStringContainsString('-c:v hevc_videotoolbox', $command);
        self::assertStringContainsString('-maxrate:v 10000k', $command);
        self::assertStringContainsString('-quality quality', $command);
        self::assertStringContainsString('-prio_speed 0', $command);
        self::assertStringContainsString('-realtime 0', $command);
        self::assertStringContainsString('-spatial_aq 1', $command);
        self::assertStringContainsString('-power_efficient 0', $command);
        self::assertStringContainsString('-max_ref_frames 4', $command);
    }

    public function test_apple_command_uses_legacy_spatialaq_name_when_exposed_by_ffmpeg(): void
    {
        $ffmpegPath = $this->createFakeFfmpegWithEncoders(
            "Encoders:\nV..... hevc_videotoolbox Apple VideoToolbox encoder\n",
            <<<'TXT'
Encoder hevc_videotoolbox [VideoToolbox H.265 Encoder]:
  -spatialaq         <int>
TXT,
        );

        $encoder = new FfmpegEncoder(
            useCpu: false,
            platform: self::darwinPlatformWithTools($ffmpegPath),
        );

        $command = $encoder->commandForFile(
            file: self::videoFile(hasRotation: false),
            baseBitrate: 8000,
            maxBitrateSpike: 1.25,
            tempFilePath: '/tmp/out.mp4',
        );

        self::assertStringContainsString('-spatialaq 1', $command);
        self::assertStringNotContainsString('-spatial_aq 1', $command);
    }

    public function test_apple_command_skips_unsupported_quality_options(): void
    {
        $ffmpegPath = $this->createFakeFfmpegWithEncoders(
            "Encoders:\nV..... hevc_videotoolbox Apple VideoToolbox encoder\n",
            <<<'TXT'
Encoder hevc_videotoolbox [VideoToolbox H.265 Encoder]:
  -quality           <int>
TXT,
        );

        $encoder = new FfmpegEncoder(
            useCpu: false,
            platform: self::darwinPlatformWithTools($ffmpegPath),
        );

        $command = $encoder->commandForFile(
            file: self::videoFile(hasRotation: false),
            baseBitrate: 8000,
            maxBitrateSpike: 1.25,
            tempFilePath: '/tmp/out.mp4',
        );

        self::assertStringContainsString('-c:v hevc_videotoolbox', $command);
        self::assertStringContainsString('-maxrate:v 10000k', $command);
        self::assertStringContainsString('-quality quality', $command);
        self::assertStringNotContainsString('-prio_speed', $command);
        self::assertStringNotContainsString('-realtime', $command);
        self::assertStringNotContainsString('-spatialaq', $command);
        self::assertStringNotContainsString('-spatial_aq', $command);
        self::assertStringNotContainsString('-power_efficient', $command);
        self::assertStringNotContainsString('-max_ref_frames', $command);
    }

    public function test_video_file_from_path_reads_probe_stream_and_rotation_metadata(): void
    {
        $sourcePath = $this->tmpDir . '/source.mp4';
        file_put_contents($sourcePath, 'video-bytes');

        $ffmpegPath = $this->createFakeFfmpegWithVideoProbe(
            <<<'JSON'
{"streams":[{"width":3840,"height":2160,"bit_rate":12000000,"pix_fmt":"yuv420p","codec_name":"h264","duration":9.5,"color_space":"bt709","color_primaries":"bt709","color_transfer":"bt709","side_data_list":[{"side_data_type":"Display Matrix"}]}]}
JSON,
        );

        $encoder = new FfmpegEncoder(
            useCpu: true,
            platform: self::platformWithTools($ffmpegPath),
        );

        $videoFile = $encoder->videoFileFromPath($sourcePath);

        self::assertSame($sourcePath, $videoFile->path);
        self::assertSame(3840, $videoFile->width);
        self::assertSame(2160, $videoFile->height);
        self::assertSame(12_000_000, $videoFile->bitRate);
        self::assertSame('yuv420p', $videoFile->pixFmt);
        self::assertSame('h264', $videoFile->codecName);
        self::assertSame(9.5, $videoFile->duration);
        self::assertSame((int) filesize($sourcePath), $videoFile->currentSize);
        self::assertSame('bt709', $videoFile->colorSpace);
        self::assertSame('bt709', $videoFile->colorPrimaries);
        self::assertSame('bt709', $videoFile->colorTransfer);
        self::assertTrue($videoFile->hasRotation);
    }

    private static function platformWithTools(string $ffmpegPath): StubPlatform
    {
        $platform = new StubPlatform();
        $platform->setTool('ffmpeg', $ffmpegPath);
        $platform->setTool('ffprobe', $ffmpegPath);

        return $platform;
    }

    private static function darwinPlatformWithTools(string $ffmpegPath): StubPlatform
    {
        $platform           = self::platformWithTools($ffmpegPath);
        $platform->isDarwin = true;

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

    private function createFakeFfmpegWithQualityScore(string $argvLogPath): string
    {
        $path = $this->tmpDir . '/fake-ffmpeg-quality.php';
        file_put_contents(
            $path,
            sprintf(
                <<<'PHP'
#!/usr/bin/env php
<?php
$argvLogPath = %s;

if (in_array('-encoders', $argv, true)) {
    echo "Encoders:\n";
    exit(0);
}

if (in_array('-filters', $argv, true)) {
    echo " .. libvmaf VV->V Calculate the VMAF score.\n";
    exit(0);
}

$filterIndex = array_search('-filter_complex', $argv, true);
if (! is_int($filterIndex) || ! isset($argv[$filterIndex + 1])) {
    exit(0);
}

file_put_contents($argvLogPath, json_encode($argv));

if (preg_match('/log_path=([^:]+)/', $argv[$filterIndex + 1], $match) !== 1) {
    fwrite(STDERR, "missing log_path in filter\n");
    exit(1);
}

$vmafPath = getcwd() . DIRECTORY_SEPARATOR . $match[1];
file_put_contents($vmafPath, '{"pooled_metrics":{"vmaf":{"harmonic_mean":91.25}}}');
exit(0);
PHP,
                var_export($argvLogPath, true),
            ),
        );
        chmod($path, 0o755);

        return $path;
    }

    private function createFakeFfmpegWithEncoders(string $encodersOutput, string $encoderHelpOutput = "encoder options\n"): string
    {
        $path = $this->tmpDir . '/fake-ffmpeg-encoders.php';
        file_put_contents(
            $path,
            sprintf(
                <<<'PHP'
#!/usr/bin/env php
<?php
$encodersOutput = %s;
$encoderHelpOutput = %s;

if (in_array('-encoders', $argv, true)) {
    echo $encodersOutput;
    exit(0);
}

if (in_array('-h', $argv, true)) {
    echo $encoderHelpOutput;
    exit(0);
}

if (in_array('-filters', $argv, true)) {
    echo " .. libvmaf VV->V Calculate the VMAF score.\n";
    exit(0);
}

exit(0);
PHP,
                var_export($encodersOutput, true),
                var_export($encoderHelpOutput, true),
            ),
        );
        chmod($path, 0o755);

        return $path;
    }

    private function createFakeFfmpegWithVideoProbe(string $probeOutput): string
    {
        $path = $this->tmpDir . '/fake-ffmpeg-probe.php';
        file_put_contents(
            $path,
            sprintf(
                <<<'PHP'
#!/usr/bin/env php
<?php
$probeOutput = %s;

if (in_array('-encoders', $argv, true)) {
    echo "Encoders:\n";
    exit(0);
}

if (in_array('-h', $argv, true)) {
    echo "encoder options\n";
    exit(0);
}

if (in_array('-filters', $argv, true)) {
    echo " .. libvmaf VV->V Calculate the VMAF score.\n";
    exit(0);
}

if (in_array('-show_streams', $argv, true)) {
    echo $probeOutput;
    exit(0);
}

exit(0);
PHP,
                var_export($probeOutput, true),
            ),
        );
        chmod($path, 0o755);

        return $path;
    }

    /** @return list<string> */
    private static function readLoggedArgv(string $argvLogPath): array
    {
        $decoded = json_decode((string) file_get_contents($argvLogPath), true);

        self::assertTrue(is_array($decoded));

        /** @var list<string> $decodedStrings */
        $decodedStrings = $decoded;

        return $decodedStrings;
    }

    /** @param list<string> $values */
    private static function indexOf(array $values, string $needle, int $offset = 0): int|null
    {
        for ($i = $offset, $count = count($values); $i < $count; $i++) {
            if ($values[$i] === $needle) {
                return $i;
            }
        }

        return null;
    }

    private static function videoFile(bool $hasRotation): VideoFile
    {
        return new VideoFile(
            path: '/tmp/source.mp4',
            width: 1920,
            height: 1080,
            bitRate: 16_000_000,
            pixFmt: 'yuv420p',
            codecName: 'h264',
            duration: 12.0,
            currentSize: 24_000_000,
            colorSpace: 'bt709',
            colorPrimaries: 'bt709',
            colorTransfer: 'bt709',
            hasRotation: $hasRotation,
        );
    }
}
