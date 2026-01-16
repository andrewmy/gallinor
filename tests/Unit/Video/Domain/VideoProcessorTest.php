<?php

declare(strict_types=1);

namespace App\Tests\Unit\Video\Domain;

use App\Video\Domain\Encoder;
use App\Video\Domain\ProcessResult;
use App\Video\Domain\VideoFile;
use App\Video\Domain\VideoProcessor;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

use function file_exists;
use function sys_get_temp_dir;
use function unlink;

final class VideoProcessorTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private MockInterface|Encoder $encoder;
    private MockInterface|LoggerInterface $logger;
    private InMemoryProcessExecutor $processExecutor;
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
        $file = self::create1080pVideo(needsEncoding: true);

        // 4MB < 5MB original
        $this->processExecutor = new InMemoryProcessExecutor(
            commandResults: [],
            fileSizes: [sys_get_temp_dir() => 4 * 1024 * 1024],
        );
        $this->processor       = new VideoProcessor($this->encoder, $this->logger, $this->processExecutor);

        // any command is fine, executor handles file creation
        $this->encoder->allows()
            ->commandForFile(Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any())
            ->andReturn('ffmpeg > /tmp/test.mp4');

        // score above threshold
        $this->encoder->allows()
            ->qualityScore(Mockery::any(), Mockery::any())
            ->andReturn(95.0);

        $this->logger->allows()->info(Mockery::any(), Mockery::any());

        $callbackInvoked = false;
        $callback        = static function (int $bitrate, float $vmafScore, int $saved) use (&$callbackInvoked): void {
            $callbackInvoked = true;
            self::assertSame(8000, $bitrate);
            self::assertSame(95.0, $vmafScore);
        };

        $result = $this->processor->processVideo($file, dryRun: false, statusCallback: $callback);

        self::assertTrue($result->success);
        self::assertFalse($result->skipped);
        self::assertSame(95.0, $result->vmafScore);
        self::assertSame(0, $result->retryCount);
        self::assertTrue($callbackInvoked);

        $this->cleanTempFile($result->outputPath);
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
            ->commandForFile(Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any())
            ->andReturn('ffmpeg > /tmp/test.mp4');

        $this->encoder->allows()->qualityScore(Mockery::any(), Mockery::any())->andReturn(95.0);
        $this->logger->allows()->info(Mockery::any(), Mockery::any());

        $linesReceived = [];
        $lineCallback  = static function (string $line) use (&$linesReceived): void {
            $linesReceived[] = $line;
        };

        $result = $this->processor->processVideo($file, dryRun: false, lineCallback: $lineCallback);

        self::assertSame(['line1', 'line2', 'line3'], $linesReceived);

        $this->cleanTempFile($result->outputPath);
    }

    public function test_retries_encoding_when_vmaf_score_is_too_low(): void
    {
        $file = self::create1080pVideo(needsEncoding: true);

        $this->processExecutor = new InMemoryProcessExecutor(
            commandResults: [],
            fileSizes: [sys_get_temp_dir() => 4 * 1024 * 1024],
        );
        $this->processor       = new VideoProcessor($this->encoder, $this->logger, $this->processExecutor);

        $callCount = 0;

        $this->encoder->allows()
            ->commandForFile(Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any())
            ->andReturn('ffmpeg > /tmp/test.mp4');

        // first call fails, second succeeds
        $this->encoder->allows()
            ->qualityScore(Mockery::any(), Mockery::any())
            ->andReturnUsing(static function () use (&$callCount) {
                $callCount++;

                return $callCount === 1 ? 85.0 : 92.0;
            });

        $this->logger->allows()->info(Mockery::any(), Mockery::any());

        $result = $this->processor->processVideo($file, dryRun: false);

        self::assertTrue($result->success);
        self::assertSame(92.0, $result->vmafScore);
        self::assertSame(1, $result->retryCount);
        self::assertSame(10000, $result->finalBitrate); // 8000 + 2000 step

        $this->cleanTempFile($result->outputPath);
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

        $this->processExecutor = new InMemoryProcessExecutor(
            commandResults: [],
            fileSizes: [sys_get_temp_dir() => 19], // Larger than original 10 bytes
        );
        $this->processor       = new VideoProcessor($this->encoder, $this->logger, $this->processExecutor);

        $this->encoder->allows()
            ->commandForFile(Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any())
            ->andReturn('ffmpeg > /tmp/test.mp4');

        $this->encoder->shouldNotReceive('qualityScore');

        $this->logger->expects()
            ->warning('Encoded file is larger than original, skipping', Mockery::on(static fn ($context) => isset($context['original_size']) &&
                isset($context['encoded_size']) &&
                $context['encoded_size'] > $context['original_size']));

        $result = $this->processor->processVideo($file, dryRun: false);

        self::assertTrue($result->skipped, 'File should be skipped when encoded version is larger');
        self::assertNull($result->vmafScore, 'VMAF should not be checked when file is larger');
        self::assertTrue($result->success, 'Processing should succeed (file was skipped)');
        self::assertSame(0, $result->retryCount, 'Should not retry when file is larger');
    }

    private function cleanTempFile(string $path): void
    {
        if (! file_exists($path)) {
            return;
        }

        @unlink($path);
    }
}
