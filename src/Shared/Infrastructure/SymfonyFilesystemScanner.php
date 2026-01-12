<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure;

use App\Shared\Domain\FilesystemScanner;
use FilesystemIterator;
use Generator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

use function rtrim;
use function trim;

use const DIRECTORY_SEPARATOR;

final readonly class SymfonyFilesystemScanner implements FilesystemScanner
{
    public function __construct(
        private Filesystem $fs,
    ) {
    }

    /**
     * @param array<string> $directories
     *
     * @return Generator<SplFileInfo>
     */
    public function scanDirectories(array $directories): Generator
    {
        foreach ($directories as $directory) {
            $normalizedDirectory = $this->normalizePath($directory);

            if (! $this->fs->exists($normalizedDirectory)) {
                throw new RuntimeException('Directory not found: ' . $normalizedDirectory);
            }

            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    directory: $normalizedDirectory,
                    flags: FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS,
                ),
            );

            foreach ($files as $file) {
                if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                    continue;
                }

                yield $file;
            }
        }
    }

    private function normalizePath(string $path): string
    {
        $trimmed = rtrim(trim($path, '"\' '), DIRECTORY_SEPARATOR);

        return Path::canonicalize($trimmed);
    }
}
