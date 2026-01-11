<?php

declare(strict_types=1);

namespace App\Domain;

use function basename;
use function dirname;
use function file_exists;
use function filesize;
use function pathinfo;

use const DIRECTORY_SEPARATOR;
use const PATHINFO_FILENAME;

final readonly class ImageFile
{
    public int $size;

    public function __construct(
        public string $path,
        public bool $isPortraitOrLivePhoto = false,
    ) {
        $this->size = (int) filesize($path);
    }

    public function optimizedPath(): string
    {
        return dirname($this->path) . DIRECTORY_SEPARATOR . pathinfo($this->path, PATHINFO_FILENAME) . '.avif';
    }

    public function hasOptimized(): bool
    {
        return file_exists($this->optimizedPath());
    }

    public function filename(): string
    {
        return basename($this->path);
    }
}
