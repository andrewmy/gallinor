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

use function is_dir;
use function is_file;
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
     * @param list<string> $paths
     *
     * @return Generator<SplFileInfo>
     */
    public function scanDirectories(array $paths): Generator
    {
        foreach ($paths as $path) {
            $normalizedPath = $this->normalizePath($path);

            if (! $this->fs->exists($normalizedPath)) {
                throw new RuntimeException('Path not found: ' . $normalizedPath);
            }

            if (is_file($normalizedPath)) {
                yield new SplFileInfo($normalizedPath);

                continue;
            }

            if (! is_dir($normalizedPath)) {
                throw new RuntimeException('Path is neither file nor directory: ' . $normalizedPath);
            }

            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    directory: $normalizedPath,
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
