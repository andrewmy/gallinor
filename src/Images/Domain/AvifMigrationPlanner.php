<?php

declare(strict_types=1);

namespace App\Images\Domain;

use App\Shared\Domain\FilesystemScanner;

use function dirname;
use function file_exists;
use function pathinfo;
use function strtolower;

use const DIRECTORY_SEPARATOR;
use const PATHINFO_FILENAME;

final readonly class AvifMigrationPlanner
{
    public function __construct(
        private FilesystemScanner $scanner,
    ) {
    }

    /** @param list<string> $directories */
    public function plan(array $directories): AvifMigrationPlan
    {
        $allAvifs        = [];
        $alreadyMigrated = [];
        $toMigrate       = [];

        foreach ($this->scanner->scanDirectories($directories) as $file) {
            if (strtolower($file->getExtension()) !== 'avif') {
                continue;
            }

            $avifPath   = $file->getPathname();
            $allAvifs[] = $avifPath;

            $targetHeic = dirname($avifPath) . DIRECTORY_SEPARATOR . pathinfo($avifPath, PATHINFO_FILENAME) . '.heic';
            if (file_exists($targetHeic)) {
                $alreadyMigrated[] = $avifPath;
                continue;
            }

            $toMigrate[] = $avifPath;
        }

        return new AvifMigrationPlan(
            allAvifs: $allAvifs,
            alreadyMigratedAvifs: $alreadyMigrated,
            toMigrateAvifs: $toMigrate,
        );
    }
}
