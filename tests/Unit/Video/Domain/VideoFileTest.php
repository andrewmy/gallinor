<?php

declare(strict_types=1);

namespace App\Tests\Unit\Video\Domain;

use App\Video\Domain\Exceptions\UnsupportedResolution;
use App\Video\Domain\VideoFile;
use PHPUnit\Framework\TestCase;

final class VideoFileTest extends TestCase
{
    private const string DEFAULT_PATH = '/path/to/video.mp4';

    public function test_suffixed_file_path_adds_suffix_before_extension(): void
    {
        $video = new VideoFile(
            path: '/path/to/video.mp4',
            width: 1920,
            height: 1080,
            bitRate: 10_000,
            pixFmt: 'yuv420p',
            codecName: 'h264',
            duration: 60.0,
            currentSize: 5_000_000,
        );

        self::assertSame('/path/to/video.optimal.mp4', $video->suffixedFilePath('optimal'));
    }

    public function test_bitrate_step_for_720p(): void
    {
        $landscape = new VideoFile(
            path: self::DEFAULT_PATH,
            width: 1280,
            height: 720,
            bitRate: 5000,
            pixFmt: 'yuv420p',
            codecName: 'h264',
            duration: 60.0,
            currentSize: 3_000_000,
        );

        self::assertSame(1000, $landscape->bitrateStep());
    }

    public function test_bitrate_step_for_1080p(): void
    {
        $landscape = new VideoFile(
            path: self::DEFAULT_PATH,
            width: 1920,
            height: 1080,
            bitRate: 10_000,
            pixFmt: 'yuv420p',
            codecName: 'h264',
            duration: 60.0,
            currentSize: 5_000_000,
        );

        self::assertSame(2000, $landscape->bitrateStep());
    }

    public function test_bitrate_step_for_4k(): void
    {
        $landscape = new VideoFile(
            path: self::DEFAULT_PATH,
            width: 3840,
            height: 2160,
            bitRate: 40_000,
            pixFmt: 'yuv420p',
            codecName: 'h264',
            duration: 60.0,
            currentSize: 20_000_000,
        );

        self::assertSame(4000, $landscape->bitrateStep());
    }

    public function test_bitrate_step_returns_null_for_unsupported_resolution(): void
    {
        $video = new VideoFile(
            path: self::DEFAULT_PATH,
            width: 640,
            height: 480,
            bitRate: 2000,
            pixFmt: 'yuv420p',
            codecName: 'h264',
            duration: 60.0,
            currentSize: 1_000_000,
        );

        self::assertNull($video->bitrateStep());
    }

    public function test_base_bitrate_for_720p(): void
    {
        $landscape = new VideoFile(
            path: self::DEFAULT_PATH,
            width: 1280,
            height: 720,
            bitRate: 5000,
            pixFmt: 'yuv420p',
            codecName: 'h264',
            duration: 60.0,
            currentSize: 3_000_000,
        );

        self::assertSame(4000, $landscape->baseBitrate());
    }

    public function test_base_bitrate_for_1080p(): void
    {
        $landscape = new VideoFile(
            path: self::DEFAULT_PATH,
            width: 1920,
            height: 1080,
            bitRate: 10_000,
            pixFmt: 'yuv420p',
            codecName: 'h264',
            duration: 60.0,
            currentSize: 5_000_000,
        );

        self::assertSame(8000, $landscape->baseBitrate());
    }

    public function test_base_bitrate_for_4k(): void
    {
        $landscape = new VideoFile(
            path: self::DEFAULT_PATH,
            width: 3840,
            height: 2160,
            bitRate: 40_000,
            pixFmt: 'yuv420p',
            codecName: 'h264',
            duration: 60.0,
            currentSize: 20_000_000,
        );

        self::assertSame(28000, $landscape->baseBitrate());
    }

    public function test_base_bitrate_throws_for_unsupported_resolution(): void
    {
        $video = new VideoFile(
            path: self::DEFAULT_PATH,
            width: 640,
            height: 480,
            bitRate: 2000,
            pixFmt: 'yuv420p',
            codecName: 'h264',
            duration: 60.0,
            currentSize: 1_000_000,
        );

        $this->expectException(UnsupportedResolution::class);
        $this->expectExceptionMessage('Unsupported dimensions 640x480');

        $video->baseBitrate();
    }

    public function test_size_estimate_calculates_correctly(): void
    {
        $video = new VideoFile(
            path: self::DEFAULT_PATH,
            width: 1920,
            height: 1080,
            bitRate: 10_000,
            pixFmt: 'yuv420p',
            codecName: 'h264',
            duration: 60.0,
            currentSize: 5_000_000,
        );

        // For bitrate 8000 kbit/s, duration 60s:
        // 8000 * 60 / 8 / 1024 = 58.59375, ceil = 59 MB

        $estimate = $video->sizeEstimate(8000);

        self::assertSame(59, $estimate);
    }
}
