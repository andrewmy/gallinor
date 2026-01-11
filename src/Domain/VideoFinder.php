<?php

declare(strict_types=1);

namespace App\Domain;

use Generator;
use RuntimeException;
use SplFileInfo;

use function str_ends_with;

final readonly class VideoFinder
{
    public function __construct(
        private Ffmpeg $ffmpeg,
    ) {}

    /**
     * Find video files from directory scan
     *
     * @param Generator<SplFileInfo> $files
     * @param callable(string, string): void|null $errorCallback Called with (filePath, errorMessage) for invalid videos
     * @return Generator<VideoFile>
     */
    public function findVideos(Generator $files, ?callable $errorCallback = null): Generator
    {
        foreach ($files as $file) {
            if (! $this->isVideoFile($file)) {
                continue;
            }

            $filePath = $file->getPathname();

            if ($this->isAuxiliaryFile($filePath)) {
                continue;
            }

            try {
                yield $this->ffmpeg->videoFileFromPath($filePath);
            } catch (RuntimeException $exception) {
                if ($errorCallback !== null) {
                    $errorCallback($filePath, $exception->getMessage());
                }
            }
        }
    }

    private function isVideoFile(SplFileInfo $file): bool
    {
        return $file->isFile() && $file->getExtension() === 'mp4';
    }

    private function isAuxiliaryFile(string $filePath): bool
    {
        return str_ends_with($filePath, '.' . VideoFile::OPTIMAL_SUFFIX . '.mp4')
            || str_ends_with($filePath, '.tmp.mp4');
    }
}
