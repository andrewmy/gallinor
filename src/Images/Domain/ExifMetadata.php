<?php

declare(strict_types=1);

namespace App\Images\Domain;

/**
 * Port for reading/writing EXIF metadata.
 *
 * Implemented by {@see Exiftool}.
 */
interface ExifMetadata
{
    /**
     * Read numeric EXIF orientation (1..8). Defaults to 1 if not present/parseable.
     */
    public function orientation(string $path): int;

    /** Copy metadata from one file to another (overwrites destination metadata). */
    public function copyAllMetadata(string $from, string $to): void;

    public function forceOrientationTo1(string $path): void;

    /**
     * Delete EXIF dimension tags that can become stale when we bake rotation.
     */
    public function deleteDerivedDimensionTags(string $path): void;

    /**
     * Dump metadata for strict verification.
     *
     * @return array<string, string> Map "GROUP:Tag" => value (stringified)
     */
    public function metadataMap(string $path): array;

    /** @return array<string, true> Filenames (with path) to skip */
    public function findPortraitAndLivePhotos(string $dir): array;
}
