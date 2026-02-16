<?php

declare(strict_types=1);

namespace App\Tests\Unit\Images\Ui\Cli;

use App\Images\Domain\CalculationSkipReason;
use App\Images\Domain\ImageFormat;
use App\Images\Domain\ImageProcessingResult;
use App\Images\Ui\Cli\ImageBatchResult;
use PHPUnit\Framework\TestCase;

final class ImageBatchResultTest extends TestCase
{
    public function test_empty_result_has_zero_counts(): void
    {
        $result = new ImageBatchResult();

        self::assertSame(0, $result->processedCount());
        self::assertSame(0, $result->skippedCount());
        self::assertSame(0, $result->erroredCount());
    }

    public function test_processed_count_returns_number_of_processed_items(): void
    {
        $result = new ImageBatchResult(
            processed: [
                '/path/image1.jpg' => new ImageProcessingResult(ImageFormat::Heic, 500_000, 1_000_000, 18, 'q', 88.5, 2.3),
                '/path/image2.jpg' => new ImageProcessingResult(ImageFormat::Heic, 400_000, 800_000, 16, 'q', 90.0, 1.8),
            ],
        );

        self::assertSame(2, $result->processedCount());
    }

    public function test_skipped_count_returns_number_of_skipped_items(): void
    {
        $result = new ImageBatchResult(
            skipped: [
                '/path/image1.jpg' => CalculationSkipReason::ReplacementNotSmaller,
                '/path/image2.jpg' => CalculationSkipReason::QualityNotAchieved,
            ],
        );

        self::assertSame(2, $result->skippedCount());
    }

    public function test_errored_count_returns_number_of_errored_items(): void
    {
        $result = new ImageBatchResult(
            errored: [
                '/path/image1.jpg' => 'Encoding failed',
                '/path/image2.jpg' => 'Quality check failed',
            ],
        );

        self::assertSame(2, $result->erroredCount());
    }

    public function test_total_bytes_after_sums_heic_sizes(): void
    {
        $result = new ImageBatchResult(
            processed: [
                '/path/image1.jpg' => new ImageProcessingResult(ImageFormat::Heic, 500_000, 1_000_000, 18, 'q', 88.5, 2.3),
                '/path/image2.jpg' => new ImageProcessingResult(ImageFormat::Heic, 400_000, 800_000, 16, 'q', 90.0, 1.8),
                '/path/image3.jpg' => new ImageProcessingResult(ImageFormat::Heic, 100_000, 500_000, 20, 'q', 92.0, 0.5),
            ],
        );

        self::assertSame(1_000_000, $result->totalBytesAfter());
    }

    public function test_total_bytes_before_sums_original_sizes(): void
    {
        $result = new ImageBatchResult(
            processed: [
                '/path/image1.jpg' => new ImageProcessingResult(ImageFormat::Heic, 500_000, 1_000_000, 18, 'q', 88.5, 2.3),
                '/path/image2.jpg' => new ImageProcessingResult(ImageFormat::Heic, 800_000, 2_000_000, 16, 'q', 90.0, 1.8),
                '/path/image3.jpg' => new ImageProcessingResult(ImageFormat::Heic, 200_000, 500_000, 20, 'q', 92.0, 0.5),
            ],
        );

        self::assertSame(3_500_000, $result->totalBytesBefore());
    }

    public function test_total_bytes_saved_calculates_difference(): void
    {
        $result = new ImageBatchResult(
            processed: [
                '/path/image1.jpg' => new ImageProcessingResult(ImageFormat::Heic, 500_000, 1_000_000, 18, 'q', 88.5, 2.3),
                '/path/image2.jpg' => new ImageProcessingResult(ImageFormat::Heic, 800_000, 2_000_000, 16, 'q', 90.0, 1.8),
            ],
        );

        // (1,000,000 - 500,000) + (2,000,000 - 800,000) = 1,700,000
        self::assertSame(1_700_000, $result->totalBytesSaved());
    }

    public function test_total_qc_time_sums_processing_times(): void
    {
        $result = new ImageBatchResult(
            processed: [
                '/path/image1.jpg' => new ImageProcessingResult(ImageFormat::Heic, 500_000, 1_000_000, 18, 'q', 88.5, 2.3),
                '/path/image2.jpg' => new ImageProcessingResult(ImageFormat::Heic, 400_000, 800_000, 16, 'q', 90.0, 1.8),
                '/path/image3.jpg' => new ImageProcessingResult(ImageFormat::Heic, 100_000, 500_000, 20, 'q', 92.0, 0.5),
            ],
        );

        self::assertSame(4.6, $result->totalQcTime());
    }

    public function test_all_categories_can_coexist(): void
    {
        $result = new ImageBatchResult(
            processed: [
                '/path/image1.jpg' => new ImageProcessingResult(ImageFormat::Heic, 500_000, 1_000_000, 18, 'q', 88.5, 2.3),
            ],
            skipped: [
                '/path/image2.jpg' => CalculationSkipReason::ReplacementNotSmaller,
            ],
            errored: ['/path/image3.jpg' => 'Encoding failed'],
        );

        self::assertSame(1, $result->processedCount());
        self::assertSame(1, $result->skippedCount());
        self::assertSame(1, $result->erroredCount());
    }
}
