<?php

declare(strict_types=1);

namespace App\Domain;

final readonly class ImageProcessingResult
{
    public function __construct(
        public int $avifSize,
        public int $cqLevel,
        public float $qualityScore,
        public float $qcTime,
    ) {
    }

    public function savings(int $originalSize): int
    {
        return $originalSize - $this->avifSize;
    }
}
