<?php

declare(strict_types=1);

namespace App\Images\Domain;

use function basename;
use function dirname;
use function file_exists;
use function pathinfo;

use const DIRECTORY_SEPARATOR;
use const PATHINFO_FILENAME;

final readonly class ImageFile
{
    public function __construct(
        public string $path,
        public int $size,
        public bool $isPortraitOrLivePhoto = false,
    ) {
    }

    public function optimizedPathFor(ImageFormat $format): string
    {
        return dirname($this->path) . DIRECTORY_SEPARATOR . pathinfo($this->path, PATHINFO_FILENAME) . '.' . $format->extension();
    }

    public function hasOptimized(ImageFormat $format): bool
    {
        return file_exists($this->optimizedPathFor($format));
    }

    public function filename(): string
    {
        return basename($this->path);
    }
}
