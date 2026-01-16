<?php

declare(strict_types=1);

namespace App\Images\Domain;

use Throwable;

final readonly class ImageProcessor
{
    public function __construct(
        private CqLevelCalculator $cqCalculator,
    ) {
    }

    /**
     * @param iterable<ImageFile>                           $images             JPEG files to process
     * @param callable(int, float, int): void               $statusCallback     Called with (cqLevel, score, saved) during quality search
     * @param callable(string, string): void                $errorCallback      Called with (fileName, errorMessage) on error
     * @param callable(string, CalculationSkipReason): void $skipCallback       Called with (fileName, reason) when skipped
     * @param callable(): void                              $afterImageCallback Called after each image is processed (for progress updates)
     *
     * @return ImageProcessorResult Processing results with processed, skipped, and errored files
     */
    public function process(
        iterable $images,
        callable $statusCallback,
        callable $errorCallback,
        callable $skipCallback,
        callable $afterImageCallback,
    ): ImageProcessorResult {
        $result = new ImageProcessorResult();

        foreach ($images as $image) {
            $fileName = $image->filename();

            try {
                $outcome = $this->cqCalculator->calculate($image, $statusCallback);

                if ($outcome instanceof CalculationSkipReason) {
                    $result->skipped[$image->path] = $outcome;
                    $skipCallback($fileName, $outcome);
                        $afterImageCallback();

                    continue;
                }

                $result->processed[$image->path] = $outcome;
            } catch (Throwable $e) {
                $result->errored[$image->path] = $e->getMessage();
                $errorCallback($fileName, $e->getMessage());
            }

            $afterImageCallback();
        }

        return $result;
    }
}
