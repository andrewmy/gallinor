<?php

declare(strict_types=1);

namespace App\Tests\Unit\Images\Domain;

use App\Images\Domain\CalculationSkipReason;
use App\Images\Domain\ImageProcessingResult;
use App\Images\Domain\ImageProcessorResult;
use PHPUnit\Framework\TestCase;

final class ImageProcessorResultTest extends TestCase
{
    public function test_empty_result_has_zero_counts(): void
    {
        $result = new ImageProcessorResult();

        self::assertSame(0, $result->processedCount());
        self::assertSame(0, $result->skippedCount());
        self::assertSame(0, $result->erroredCount());
    }

    public function test_processed_count_returns_number_of_processed_items(): void
    {
        $result = new ImageProcessorResult(
            processed: [
                '/path/image1.jpg' => new ImageProcessingResult(500_000, 1_000_000, 18, 88.5, 2.3),
                '/path/image2.jpg' => new ImageProcessingResult(400_000, 800_000, 16, 90.0, 1.8),
            ],
        );

        self::assertSame(2, $result->processedCount());
    }

    public function test_skipped_count_returns_number_of_skipped_items(): void
    {
        $result = new ImageProcessorResult(
            skipped: [
                '/path/image1.jpg' => CalculationSkipReason::AvifNotSmaller,
                '/path/image2.jpg' => CalculationSkipReason::QualityNotAchieved,
            ],
        );

        self::assertSame(2, $result->skippedCount());
    }

    public function test_errored_count_returns_number_of_errored_items(): void
    {
        $result = new ImageProcessorResult(
            errored: [
                '/path/image1.jpg' => 'Encoding failed',
                '/path/image2.jpg' => 'Quality check failed',
            ],
        );

        self::assertSame(2, $result->erroredCount());
    }

    public function test_total_bytes_after_sums_avif_sizes(): void
    {
        $result = new ImageProcessorResult(
            processed: [
                '/path/image1.jpg' => new ImageProcessingResult(500_000, 1_000_000, 18, 88.5, 2.3),
                '/path/image2.jpg' => new ImageProcessingResult(400_000, 800_000, 16, 90.0, 1.8),
                '/path/image3.jpg' => new ImageProcessingResult(100_000, 500_000, 20, 92.0, 0.5),
            ],
        );

        self::assertSame(1_000_000, $result->totalBytesAfter());
    }

    public function test_total_bytes_before_sums_original_sizes(): void
    {
        $result = new ImageProcessorResult(
            processed: [
                '/path/image1.jpg' => new ImageProcessingResult(500_000, 1_000_000, 18, 88.5, 2.3),
                '/path/image2.jpg' => new ImageProcessingResult(800_000, 2_000_000, 16, 90.0, 1.8),
                '/path/image3.jpg' => new ImageProcessingResult(200_000, 500_000, 20, 92.0, 0.5),
            ],
        );

        self::assertSame(3_500_000, $result->totalBytesBefore());
    }

    public function test_total_bytes_saved_calculates_difference(): void
    {
        $result = new ImageProcessorResult(
            processed: [
                '/path/image1.jpg' => new ImageProcessingResult(500_000, 1_000_000, 18, 88.5, 2.3),
                '/path/image2.jpg' => new ImageProcessingResult(800_000, 2_000_000, 16, 90.0, 1.8),
            ],
        );

        // (1,000,000 - 500,000) + (2,000,000 - 800,000) = 1,700,000
        self::assertSame(1_700_000, $result->totalBytesSaved());
    }

    public function test_total_qc_time_sums_processing_times(): void
    {
        $result = new ImageProcessorResult(
            processed: [
                '/path/image1.jpg' => new ImageProcessingResult(500_000, 1_000_000, 18, 88.5, 2.3),
                '/path/image2.jpg' => new ImageProcessingResult(400_000, 800_000, 16, 90.0, 1.8),
                '/path/image3.jpg' => new ImageProcessingResult(100_000, 500_000, 20, 92.0, 0.5),
            ],
        );

        self::assertSame(4.6, $result->totalQcTime());
    }

    public function test_all_categories_can_coexist(): void
    {
        $result = new ImageProcessorResult(
            processed: [
                '/path/image1.jpg' => new ImageProcessingResult(500_000, 1_000_000, 18, 88.5, 2.3),
            ],
            skipped: [
                '/path/image2.jpg' => CalculationSkipReason::AvifNotSmaller,
            ],
            errored: ['/path/image3.jpg' => 'Encoding failed'],
        );

        self::assertSame(1, $result->processedCount());
        self::assertSame(1, $result->skippedCount());
        self::assertSame(1, $result->erroredCount());
    }
}
