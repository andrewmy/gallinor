<?php

declare(strict_types=1);

namespace App\Images\Domain;

final readonly class ImageProcessingResult
{
    public function __construct(
        public int $avifSize,
        public int $originalSize,
        public int $cqLevel,
        public float $qualityScore,
        public float $qcTime,
    ) {
    }

    public function savings(): int
    {
        return $this->originalSize - $this->avifSize;
    }
}
