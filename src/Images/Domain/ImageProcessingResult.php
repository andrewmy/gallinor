<?php

declare(strict_types=1);

namespace App\Images\Domain;

final readonly class ImageProcessingResult
{
    public function __construct(
        public ImageFormat $format,
        public int $optimizedSize,
        public int $originalSize,
        public int $qualityValue,
        public string $qualityLabel,
        public float $qualityScore,
        public float $qcTime,
    ) {
    }

    public function savings(): int
    {
        return $this->originalSize - $this->optimizedSize;
    }
}
