<?php

declare(strict_types=1);

namespace App\Video\Domain;

final readonly class VideoProcessResult
{
    public function __construct(
        public bool $success,
        public bool $skipped,
        public float|null $vmafScore,
        public int $originalSize,
        public int $newSize,
        public float $qcTime,
        public int $finalBitrate,
        public int $retryCount,
        public string $outputPath,
        public bool $keptExistingOptimal = false,
    ) {
    }

    public function savings(): int
    {
        return $this->originalSize - $this->newSize;
    }
}
