<?php

declare(strict_types=1);

namespace App\Domain;

use Generator;
use SplFileInfo;

interface FilesystemScanner
{
    /**
     * @param list<string> $directories Paths to directories to scan
     *
     * @return Generator<SplFileInfo> Yields SplFileInfo objects for each file found
     */
    public function scanDirectories(array $directories): Generator;
}
