<?php

declare(strict_types=1);

namespace App\Images\Domain;

use function count;
use function filesize;

final class ImageProcessorResult
{
    /**
     * @param array<string, ImageProcessingResult> $processed Map of JPEG path => result
     * @param array<string, CalculationSkipReason> $skipped   Map of JPEG path => skip reason
     * @param array<string, string>                $errored   Map of JPEG path => error message
     */
    public function __construct(
        public array $processed = [],
        public array $skipped = [],
        public array $errored = [],
    ) {
    }

    public function processedCount(): int
    {
        return count($this->processed);
    }

    public function skippedCount(): int
    {
        return count($this->skipped);
    }

    public function erroredCount(): int
    {
        return count($this->errored);
    }

    public function totalBytesBefore(): int
    {
        $total = 0;

        foreach ($this->processed as $path => $result) {
            $total += (int) filesize($path);
        }

        return $total;
    }

    public function totalBytesAfter(): int
    {
        $total = 0;

        foreach ($this->processed as $result) {
            $total += $result->avifSize;
        }

        return $total;
    }

    public function totalBytesSaved(): int
    {
        return $this->totalBytesBefore() - $this->totalBytesAfter();
    }

    public function totalQcTime(): float
    {
        $total = 0.0;

        foreach ($this->processed as $result) {
            $total += $result->qcTime;
        }

        return $total;
    }
}
