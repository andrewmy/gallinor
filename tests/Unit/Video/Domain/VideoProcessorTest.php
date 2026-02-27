<?php

declare(strict_types=1);

namespace App\Tests\Unit\Video\Domain;

use App\Shared\Domain\ProcessExecutor;
use App\Shared\Domain\ProcessResult;
use App\Tests\Shared\InMemoryProcessExecutor;
use App\Video\Domain\Encoder;
use App\Video\Domain\VideoFile;
use App\Video\Domain\VideoProcessor;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

use function max;
use function min;
use function sprintf;
use function sys_get_temp_dir;

final class VideoProcessorTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private MockInterface|Encoder $encoder;
    private MockInterface|LoggerInterface $logger;
    private ProcessExecutor $processExecutor;
    private VideoProcessor $processor;

    protected function setUp(): void
    {
        $this->encoder         = Mockery::mock(Encoder::class);
        $this->logger          = Mockery::mock(LoggerInterface::class);
        $this->processExecutor = new InMemoryProcessExecutor();
        $this->processor       = new VideoProcessor($this->encoder, $this->logger, $this->processExecutor);
    }

    public function test_skips_when_bitrate_is_acceptable(): void
    {
        $file = new VideoFile(
            path: '/path/to/video.mp4',
            width: 1920,
            height: 1080,
            bitRate: 8_000_000, // within 10% overhead of 8000
            pixFmt: 'yuv420p',
            codecName: 'h264',
            duration: 60.0,
            currentSize: 5_000_000,
        );

        $this->encoder->shouldNotReceive('qualityScore');
        $this->encoder->shouldNotReceive('commandForFile');

        $result = $this->processor->processVideo($file);

        self::assertTrue($result->success);
        self::assertTrue($result->skipped);
        self::assertSame(100.0, $result->vmafScore);
        self::assertSame(0, $result->retryCount);
        self::assertSame($file->path, $result->outputPath);
    }

    public function test_dry_run_returns_projected_size_without_encoding(): void
    {
        $file = new VideoFile(
            path: '/path/to/video.mp4',
            width: 1920,
            height: 1080,
            bitRate: 15_000_000, // needs re-encoding
            pixFmt: 'yuv420p',
            codecName: 'h264',
            duration: 60.0,
            currentSize: 10_000_000,
        );

        $this->encoder->shouldNotReceive('qualityScore');
        $this->encoder->shouldNotReceive('commandForFile');

        $result = $this->processor->processVideo($file, dryRun: true);

        self::assertTrue($result->success);
        self::assertFalse($result->skipped);
        self::assertNull($result->vmafScore);
        self::assertSame(0, $result->retryCount);
        self::assertGreaterThan(0, $result->newSize); // projected size
        self::assertStringContainsString('.optimal.mp4', $result->outputPath);
    }

    public function test_successful_encoding_on_first_attempt(): void
    {
        $file        = self::create1080pVideo(needsEncoding: true);
        $encodedSize = 4 * 1024 * 1024; // 4MB < 5MB original

        $this->processExecutor = new InMemoryProcessExecutor(
            commandResults: [],
            fileSizes: [sys_get_temp_dir() => $encodedSize],
        );
        $this->processor       = new VideoProcessor($this->encoder, $this->logger, $this->processExecutor);

        $this->encoder->allows()
            ->commandForFile(Mockery::any(), Mockery::any(), Mockery::any(), Mockery::type('string'))
            ->andReturnUsing(static function ($unusedFile, $unusedBaseBitrate, $unusedMaxSpike, $path) {
                return 'ffmpeg > ' . $path;
            });

        // score above threshold
        $this->encoder->allows()
            ->qualityScore(Mockery::any(), Mockery::any())
            ->andReturn(95.0);

        $this->logger->allows()->info(Mockery::any(), Mockery::any());

        $callbackInvoked = false;
        $callback        = static function (int $bitrate, float $vmafScore, int $saved, float $encodeSeconds, float $scoreSeconds) use (&$callbackInvoked): void {
            $callbackInvoked = true;
            self::assertSame(8000, $bitrate);
            self::assertSame(95.0, $vmafScore);
            self::assertGreaterThanOrEqual(0.0, $encodeSeconds);
            self::assertGreaterThanOrEqual(0.0, $scoreSeconds);
        };

        $result = $this->processor->processVideo($file, dryRun: false, statusCallback: $callback);

        self::assertTrue($result->success);
        self::assertFalse($result->skipped);
        self::assertSame(95.0, $result->vmafScore);
        self::assertSame(0, $result->retryCount);
        self::assertTrue($callbackInvoked);
    }

    public function test_line_callback_is_invoked_for_each_output_line(): void
    {
        $file = self::create1080pVideo(needsEncoding: true);

        $this->processExecutor = new InMemoryProcessExecutor(
            commandResults: [
                'ffmpeg' => new ProcessResult(0, ['line1', 'line2', 'line3']),
            ],
            fileSizes: [sys_get_temp_dir() => 4 * 1024 * 1024],
        );
        $this->processor       = new VideoProcessor($this->encoder, $this->logger, $this->processExecutor);

        $this->encoder->allows()
            ->commandForFile(Mockery::any(), Mockery::any(), Mockery::any(), Mockery::type('string'))
            ->andReturnUsing(static function ($unusedFile, $unusedBaseBitrate, $unusedMaxSpike, $path) {
                return 'ffmpeg > ' . $path;
            });

        $this->encoder->allows()->qualityScore(Mockery::any(), Mockery::any())->andReturn(95.0);
        $this->logger->allows()->info(Mockery::any(), Mockery::any());

        $linesReceived = [];
        $lineCallback  = static function (string $line) use (&$linesReceived): void {
            $linesReceived[] = $line;
        };

        $result = $this->processor->processVideo($file, dryRun: false, lineCallback: $lineCallback);

        self::assertSame(['line1', 'line2', 'line3'], $linesReceived);
    }

    public function test_attempt_start_callback_receives_bitrate_for_each_attempt(): void
    {
        $file = self::create1080pVideo(needsEncoding: true);

        $this->processExecutor = new InMemoryProcessExecutor(
            commandResults: [],
            fileSizes: [sys_get_temp_dir() => 4 * 1024 * 1024],
        );
        $this->processor       = new VideoProcessor($this->encoder, $this->logger, $this->processExecutor);

        $this->encoder->allows()
            ->commandForFile(Mockery::any(), Mockery::any(), Mockery::any(), Mockery::type('string'))
            ->andReturnUsing(static function ($unusedFile, $unusedBaseBitrate, $unusedMaxSpike, $path) {
                return 'ffmpeg > ' . $path;
            });

        $callCount  = 0;
        $vmafScores = [85.0, 92.0, 89.0];
        $this->encoder->allows()
            ->qualityScore(Mockery::any(), Mockery::any())
            ->andReturnUsing(static function () use (&$callCount, $vmafScores) {
                return $vmafScores[$callCount++];
            });

        $this->logger->allows()->info(Mockery::any(), Mockery::any());

        $attemptBitrates = [];
        $result          = $this->processor->processVideo(
            file: $file,
            dryRun: false,
            attemptStartCallback: static function (int $bitrateKbps) use (&$attemptBitrates): void {
                $attemptBitrates[] = $bitrateKbps;
            },
        );

        self::assertTrue($result->success);
        self::assertSame([8_000, 10_432, 9_932], $attemptBitrates);
    }

    public function test_scoring_start_callback_receives_bitrate_encoded_size_and_encode_seconds_for_each_attempt(): void
    {
        $file = self::create1080pVideo(needsEncoding: true);

        $encodedSize           = 4 * 1024 * 1024;
        $this->processExecutor = new InMemoryProcessExecutor(
            commandResults: [],
            fileSizes: [sys_get_temp_dir() => $encodedSize],
        );
        $this->processor       = new VideoProcessor($this->encoder, $this->logger, $this->processExecutor);

        $this->encoder->allows()
            ->commandForFile(Mockery::any(), Mockery::any(), Mockery::any(), Mockery::type('string'))
            ->andReturnUsing(static function ($unusedFile, $unusedBaseBitrate, $unusedMaxSpike, $path) {
                return 'ffmpeg > ' . $path;
            });

        $callCount  = 0;
        $vmafScores = [85.0, 92.0, 89.0];
        $this->encoder->allows()
            ->qualityScore(Mockery::any(), Mockery::any())
            ->andReturnUsing(static function () use (&$callCount, $vmafScores) {
                return $vmafScores[$callCount++];
            });

        $this->logger->allows()->info(Mockery::any(), Mockery::any());

        $scoringStarts = [];
        $result        = $this->processor->processVideo(
            file: $file,
            dryRun: false,
            scoringStartCallback: static function (int $bitrateKbps, int $processedSize, float $encodeSeconds) use (&$scoringStarts): void {
                $scoringStarts[] = [$bitrateKbps, $processedSize, $encodeSeconds];
            },
        );

        self::assertTrue($result->success);
        self::assertCount(3, $scoringStarts);
        self::assertSame(
            [
                [8_000, $encodedSize],
                [10_432, $encodedSize],
                [9_932, $encodedSize],
            ],
            [
                [$scoringStarts[0][0], $scoringStarts[0][1]],
                [$scoringStarts[1][0], $scoringStarts[1][1]],
                [$scoringStarts[2][0], $scoringStarts[2][1]],
            ],
        );
        self::assertGreaterThanOrEqual(0.0, $scoringStarts[0][2]);
        self::assertGreaterThanOrEqual(0.0, $scoringStarts[1][2]);
        self::assertGreaterThanOrEqual(0.0, $scoringStarts[2][2]);
    }

    public function test_adaptive_bitrate_step_for_near_threshold_vmaf(): void
    {
        $this->assertAdaptiveBitrateStep(89.0, (int) (2000 * max(1.0, min(4.0, 1.8 ** (1.0 / 15)))));
    }

    public function test_adaptive_bitrate_step_for_close_to_threshold_vmaf(): void
    {
        $this->assertAdaptiveBitrateStep(85.0, (int) (2000 * max(1.0, min(4.0, 1.8 ** (5.0 / 15)))));
    }

    public function test_adaptive_bitrate_step_for_moderate_distance_vmaf(): void
    {
        $this->assertAdaptiveBitrateStep(70.0, (int) (2000 * max(1.0, min(4.0, 1.8 ** (20.0 / 15)))));
    }

    public function test_adaptive_bitrate_step_for_very_low_vmaf(): void
    {
        $this->assertAdaptiveBitrateStep(20.0, (int) (2000 * 4.0));
    }

    public function test_steps_down_when_initial_vmaf_has_large_headroom(): void
    {
        $file = self::create1080pVideo(needsEncoding: true);

        $this->processExecutor = new InMemoryProcessExecutor(
            commandResults: [],
            fileSizes: [],
            sizeSequence: [
                4_500_000, // 8000k
                3_400_000, // 6000k (better, still acceptable)
                3_700_000, // 4000k (fails quality, stop)
                3_300_000, // 5000k (refinement probe fails, keep 6000k)
                3_250_000, // 5500k (refinement probe fails, keep 6000k)
            ],
        );
        $this->processor       = new VideoProcessor($this->encoder, $this->logger, $this->processExecutor);

        $this->encoder->allows()
            ->commandForFile(Mockery::any(), Mockery::any(), Mockery::any(), Mockery::type('string'))
            ->andReturnUsing(static function ($unusedFile, $baseBitrate, $unusedMaxSpike, $path) {
                return sprintf('ffmpeg bitrate=%d > %s', $baseBitrate, $path);
            });

        $callCount  = 0;
        $vmafScores = [97.0, 93.0, 89.0, 89.5, 89.2];
        $this->encoder->allows()
            ->qualityScore(Mockery::any(), Mockery::any())
            ->andReturnUsing(static function () use (&$callCount, $vmafScores) {
                return $vmafScores[$callCount++];
            });

        $this->logger->allows()->info(Mockery::any(), Mockery::any());

        $result = $this->processor->processVideo($file, dryRun: false);

        self::assertTrue($result->success);
        self::assertFalse($result->skipped);
        self::assertSame(6000, $result->finalBitrate);
        self::assertSame(93.0, $result->vmafScore);
        self::assertSame(3_400_000, $result->newSize);
    }

    public function test_stops_coarse_downward_search_when_vmaf_is_within_threshold_plus_one(): void
    {
        $file = self::create4kVideo(needsEncoding: true);

        $this->processExecutor = new InMemoryProcessExecutor(
            commandResults: [],
            fileSizes: [],
            sizeSequence: [
                50_000_000, // 28000k
                44_000_000, // 24000k
                38_000_000, // 20000k
                33_000_000, // 16000k
                28_000_000, // 12000k
                23_000_000, // 8000k (pass in [90, 91], stop)
            ],
        );
        $this->processor       = new VideoProcessor($this->encoder, $this->logger, $this->processExecutor);

        $this->encoder->allows()
            ->commandForFile(Mockery::any(), Mockery::any(), Mockery::any(), Mockery::type('string'))
            ->andReturnUsing(static function ($unusedFile, $baseBitrate, $unusedMaxSpike, $path) {
                return sprintf('ffmpeg bitrate=%d > %s', $baseBitrate, $path);
            });

        $callCount  = 0;
        $vmafScores = [96.3, 95.9, 95.5, 94.6, 93.2, 90.5];
        $this->encoder->allows()
            ->qualityScore(Mockery::any(), Mockery::any())
            ->andReturnUsing(static function () use (&$callCount, $vmafScores) {
                return $vmafScores[$callCount++];
            });

        $this->logger->allows()->info(Mockery::any(), Mockery::any());

        $result = $this->processor->processVideo($file, dryRun: false);

        self::assertTrue($result->success);
        self::assertFalse($result->skipped);
        self::assertSame(8_000, $result->finalBitrate);
        self::assertSame(90.5, $result->vmafScore);
        self::assertSame(23_000_000, $result->newSize);
    }

    public function test_refines_downward_after_retry_with_smaller_steps(): void
    {
        $file = self::create1080pVideo(needsEncoding: true);

        $this->processExecutor = new InMemoryProcessExecutor(
            commandResults: [],
            fileSizes: [],
            sizeSequence: [
                4_700_000, // 8000k (fails quality)
                4_200_000, // 10432k (passes after retry)
                4_050_000, // 9932k (passes and smaller)
                3_950_000, // 9432k (fails quality, stop)
            ],
        );
        $this->processor       = new VideoProcessor($this->encoder, $this->logger, $this->processExecutor);

        $this->encoder->allows()
            ->commandForFile(Mockery::any(), Mockery::any(), Mockery::any(), Mockery::type('string'))
            ->andReturnUsing(static function ($unusedFile, $baseBitrate, $unusedMaxSpike, $path) {
                return sprintf('ffmpeg bitrate=%d > %s', $baseBitrate, $path);
            });

        $callCount  = 0;
        $vmafScores = [85.0, 92.0, 91.0, 89.5];
        $this->encoder->allows()
            ->qualityScore(Mockery::any(), Mockery::any())
            ->andReturnUsing(static function () use (&$callCount, $vmafScores) {
                return $vmafScores[$callCount++];
            });

        $this->logger->allows()->info(Mockery::any(), Mockery::any());

        $result = $this->processor->processVideo($file, dryRun: false);

        self::assertTrue($result->success);
        self::assertFalse($result->skipped);
        self::assertSame(1, $result->retryCount);
        self::assertSame(9_932, $result->finalBitrate);
        self::assertSame(91.0, $result->vmafScore);
        self::assertSame(4_050_000, $result->newSize);
    }

    private function assertAdaptiveBitrateStep(float $initialVmaf, int $expectedAdaptiveStep): void
    {
        $this->processExecutor = new InMemoryProcessExecutor(
            commandResults: [],
            fileSizes: [sys_get_temp_dir() => 4 * 1024 * 1024],
        );
        $this->processor       = new VideoProcessor($this->encoder, $this->logger, $this->processExecutor);

        $this->encoder->allows()
            ->commandForFile(Mockery::any(), Mockery::any(), Mockery::any(), Mockery::type('string'))
            ->andReturnUsing(static function ($unusedFile, $unusedBaseBitrate, $unusedMaxSpike, $path) {
                return 'ffmpeg > ' . $path;
            });

        $callCount  = 0;
        $vmafScores = [$initialVmaf, 92.0, 89.0];
        $this->encoder->allows()
            ->qualityScore(Mockery::any(), Mockery::any())
            ->andReturnUsing(static function () use (&$callCount, $vmafScores) {
                return $vmafScores[$callCount++];
            });

        $this->logger->allows()->info(Mockery::any(), Mockery::any());

        $file   = self::create1080pVideo(needsEncoding: true);
        $result = $this->processor->processVideo($file, dryRun: false);

        self::assertTrue($result->success);
        self::assertSame(92.0, $result->vmafScore);
        self::assertSame(1, $result->retryCount);
        self::assertSame(8000 + $expectedAdaptiveStep, $result->finalBitrate);
    }

    public function test_static_is_bitrate_acceptable_method(): void
    {
        $file = new VideoFile(
            path: '/path/to/video.mp4',
            width: 1920,
            height: 1080,
            bitRate: 8_000_000,
            pixFmt: 'yuv420p',
            codecName: 'h264',
            duration: 60.0,
            currentSize: 5_000_000,
        );

        self::assertTrue(VideoProcessor::isBitrateAcceptable($file, 8000));
    }

    public function test_static_is_bitrate_acceptable_returns_false_when_too_high(): void
    {
        $file = new VideoFile(
            path: '/path/to/video.mp4',
            width: 1920,
            height: 1080,
            bitRate: 10_000_000,
            pixFmt: 'yuv420p',
            codecName: 'h264',
            duration: 60.0,
            currentSize: 5_000_000,
        );

        self::assertFalse(VideoProcessor::isBitrateAcceptable($file, 8000));
    }

    private static function create1080pVideo(bool $needsEncoding = true): VideoFile
    {
        return new VideoFile(
            path: sys_get_temp_dir() . '/gallinor_test_video.mp4',
            width: 1920,
            height: 1080,
            bitRate: $needsEncoding ? 15_000_000 : 8_000_000,
            pixFmt: 'yuv420p',
            codecName: 'h264',
            duration: 60.0,
            currentSize: 5_000_000,
        );
    }

    private static function create4kVideo(bool $needsEncoding = true): VideoFile
    {
        return new VideoFile(
            path: sys_get_temp_dir() . '/gallinor_test_video_4k.mp4',
            width: 3840,
            height: 2160,
            bitRate: $needsEncoding ? 45_000_000 : 28_000_000,
            pixFmt: 'yuv420p',
            codecName: 'h264',
            duration: 60.0,
            currentSize: 60_000_000,
        );
    }

    public function test_skips_when_encoded_file_is_larger_than_original(): void
    {
        $file = new VideoFile(
            path: sys_get_temp_dir() . '/gallinor_test_video.mp4',
            width: 1920,
            height: 1080,
            bitRate: 15_000_000, // needs re-encoding
            pixFmt: 'yuv420p',
            codecName: 'h264',
            duration: 60.0,
            currentSize: 10, // Very small - any encoded file will be larger
        );

        $encodedSize = 19; // Larger than original 10 bytes

        $this->processExecutor = new InMemoryProcessExecutor(
            commandResults: [],
            fileSizes: [sys_get_temp_dir() => $encodedSize],
        );
        $this->processor       = new VideoProcessor($this->encoder, $this->logger, $this->processExecutor);

        $this->encoder->allows()
            ->commandForFile(Mockery::any(), Mockery::any(), Mockery::any(), Mockery::type('string'))
            ->andReturnUsing(static function ($unusedFile, $unusedBaseBitrate, $unusedMaxSpike, $path) {
                return 'ffmpeg > ' . $path;
            });

        $this->encoder->shouldNotReceive('qualityScore');

        $this->logger->shouldReceive('warning')
            ->once()
            ->with('Encoded file is larger than original, skipping', Mockery::on(static fn ($context) => isset($context['original_size']) &&
                isset($context['encoded_size']) &&
                $context['encoded_size'] > $context['original_size']));

        $result = $this->processor->processVideo($file, dryRun: false);

        self::assertTrue($result->skipped, 'File should be skipped when encoded version is larger');
        self::assertNull($result->vmafScore, 'VMAF should not be checked when file is larger');
        self::assertTrue($result->success, 'Processing should succeed (file was skipped)');
        self::assertSame(0, $result->retryCount, 'Should not retry when file is larger');
    }
}
