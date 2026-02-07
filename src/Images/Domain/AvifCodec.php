<?php

declare(strict_types=1);

namespace App\Images\Domain;

final readonly class AvifCodec implements ImageCodec
{
    public function __construct(
        private LibAvifTools $tools,
    ) {
    }

    public function format(): ImageFormat
    {
        return ImageFormat::Avif;
    }

    public function qualityLabel(): string
    {
        return 'cq';
    }

    public function encodeFromPng(string $sourcePng, string $targetPath, int $quality): void
    {
        $this->tools->encodePngToAvif($sourcePng, $targetPath, $quality);
    }

    public function decodeToPng(string $sourcePath, string $targetPng): void
    {
        $this->tools->decodeAvifToPng($sourcePath, $targetPng);
    }
}
