<?php

declare(strict_types=1);

namespace App\Video\Domain;

use RuntimeException;

interface Encoder
{
    /** @throws RuntimeException */
    public function videoFileFromPath(string $filePath): VideoFile;

    public function commandForFile(VideoFile $file, int $baseBitrate, float $maxBitrateSpike, string $tempFilePath): string;

    public function qualityScore(string $originalFilePath, string $processedFilePath, int $subsample = 10): float;

    /** @throws RuntimeException */
    public function describeCapabilities(callable $writer): void;
}
