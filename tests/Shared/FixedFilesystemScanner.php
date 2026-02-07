<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Shared\Domain\FilesystemScanner;
use Generator;
use SplFileInfo;

/**
 * Minimal scanner for unit tests: yields a fixed set of files.
 */
final readonly class FixedFilesystemScanner implements FilesystemScanner
{
    /** @param list<string> $filePaths */
    public function __construct(
        private array $filePaths,
    ) {
    }

    /** @inheritDoc */
    public function scanDirectories(array $directories): Generator
    {
        foreach ($this->filePaths as $filePath) {
            yield new SplFileInfo($filePath);
        }
    }
}
