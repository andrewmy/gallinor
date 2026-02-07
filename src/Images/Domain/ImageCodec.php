<?php

declare(strict_types=1);

namespace App\Images\Domain;

interface ImageCodec
{
    public function format(): ImageFormat;

    public function qualityLabel(): string;

    /**
     * Encode a PNG to the codec's format using codec-specific quality value.
     */
    public function encodeFromPng(string $sourcePng, string $targetPath, int $quality): void;

    /**
     * Decode a codec file to a PNG (for QC).
     */
    public function decodeToPng(string $sourcePath, string $targetPng): void;
}
