<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Images\Domain\ExifMetadata;

final readonly class StubExifMetadata implements ExifMetadata
{
    /** @param array<string, true> $portraitAndLivePhotos */
    public function __construct(
        private array $portraitAndLivePhotos = [],
    ) {
    }

    public function orientation(string $path): int
    {
        return 1;
    }

    public function copyAllMetadata(string $from, string $to): void
    {
    }

    public function restoreCriticalCaptureMetadata(string $from, string $to): void
    {
    }

    public function forceOrientationTo1(string $path): void
    {
    }

    public function deleteDerivedDimensionTags(string $path): void
    {
    }

    /** @return array<string, string> */
    public function metadataMap(string $path): array
    {
        return [];
    }

    /** @return array<string, true> */
    public function findPortraitAndLivePhotos(string $dir): array
    {
        return $this->portraitAndLivePhotos;
    }
}
