<?php

declare(strict_types=1);

namespace App\Domain;

use function ceil;
use function pathinfo;

use const DIRECTORY_SEPARATOR;

final readonly class VideoFile
{
    public const string OPTIMAL_SUFFIX = 'optimal';

    public function __construct(
        public string $path,
        public int $width,
        public int $height,
        public int $bitRate,
        public string $pixFmt,
        public string $codecName,
        public float $duration,
        public int $currentSizeKb,
        public string|null $colorSpace = null,
        public string|null $colorPrimaries = null,
        public string|null $colorTransfer = null,
    ) {
    }

    public function suffixedFilePath(string $suffix): string
    {
        $pathParts = pathinfo($this->path);

        return $pathParts['dirname'] . DIRECTORY_SEPARATOR . $pathParts['filename'] . '.' . $suffix . '.mp4';
    }

    public function bitrateStep(): int|null
    {
        return match (true) {
            $this->width === 1280 && $this->height === 720, $this->width === 720 && $this->height === 1280 => 1000,
            $this->width === 1920 && $this->height === 1080, $this->width === 1080 && $this->height === 1920 => 2000,
            $this->width === 3840 && $this->height === 2160, $this->width === 2160 && $this->height === 3840 => 4000,
            default => null,
        };
    }

    public function baseBitrate(): int|null
    {
        return match (true) {
            $this->width === 1280 && $this->height === 720, $this->width === 720 && $this->height === 1280 => 4000,
            $this->width === 1920 && $this->height === 1080, $this->width === 1080 && $this->height === 1920 => 8000,
            $this->width === 3840 && $this->height === 2160, $this->width === 2160 && $this->height === 3840 => 28000,
            default => null,
        };
    }

    public function sizeEstimate(int $bitrate): int
    {
        return (int) ceil($bitrate * $this->duration / 8);
    }
}
