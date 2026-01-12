<?php

declare(strict_types=1);

namespace App\Images\Domain;

use App\Shared\Domain\Platform;
use Generator;
use SplFileInfo;

use function basename;
use function count;
use function dirname;
use function escapeshellarg;
use function exec;
use function filesize;
use function glob;
use function preg_match;
use function sprintf;
use function strtolower;
use function trim;

use const DIRECTORY_SEPARATOR;

/**
 * Verifies ARW files are archived before deletion.
 *
 * Scans for ARW files and cross-references them against tar.xz archives
 * to determine which files are safe to remove.
 */
final readonly class ArchiveVerifier
{
    private string $tarPath;

    public function __construct(
        private Platform $platform,
    ) {
        $this->tarPath = $this->platform->findTool('tar');
    }

    /**
     * @param Generator<SplFileInfo> $files File iterator from filesystem scanner
     *
     * @return array<string, list<string>> Directory => [filenames not archived]
     */
    public function getUnarchivedArwsByDir(Generator $files): array
    {
        $unarchivedByDir = [];

        /** @var array<string, array<string, true>> $archivedFilesCache */
        $archivedFilesCache = [];

        foreach ($files as $file) {
            $filePath  = $file->getPathname();
            $extension = strtolower($file->getExtension());

            if ($extension !== 'arw') {
                continue;
            }

            $dir = dirname($filePath);

            if (! isset($archivedFilesCache[$dir])) {
                $archivedFilesCache[$dir] = $this->getArchivedFilesInDir($dir);
            }

            $filename = basename($filePath);
            if (isset($archivedFilesCache[$dir][$filename])) {
                continue;
            }

            $unarchivedByDir[$dir][] = $filePath;
        }

        return $unarchivedByDir;
    }

    /**
     * @param Generator<SplFileInfo>      $files      File iterator from filesystem scanner
     * @param callable(string): void|null $onProgress Callback for each ARW found (receives file path)
     */
    public function verify(Generator $files, callable|null $onProgress = null): ArchiveVerificationResult
    {
        $arwsToRemove           = [];
        $unarchivedByDir        = [];
        $warnings               = [];
        $arwsFound              = 0;
        $archiveReplacementSize = 0;

        /** @var array<string, array<string, true>> $archivedFilesCache */
        $archivedFilesCache = [];

        /** @var array<string, true> $archiveSizesCounted */
        $archiveSizesCounted = [];

        foreach ($files as $file) {
            $filePath  = $file->getPathname();
            $extension = strtolower($file->getExtension());
            $dir       = dirname($filePath);

            if ($extension !== 'arw') {
                continue;
            }

            $arwsFound++;

            if (! isset($archivedFilesCache[$dir])) {
                $archivedFilesCache[$dir] = $this->getArchivedFilesInDir($dir);
            }

            $filename = basename($filePath);
            if (! isset($archivedFilesCache[$dir][$filename])) {
                $unarchivedByDir[$dir][] = $filePath;
                continue;
            }

            $arwsToRemove[] = $filePath;

            if (! isset($archiveSizesCounted[$dir])) {
                $archiveSizesCounted[$dir] = true;
                $archiveReplacementSize   += $this->getArchiveSizeInDir($dir);
            }

            if ($onProgress === null) {
                continue;
            }

            $onProgress($filePath);
        }

        foreach ($unarchivedByDir as $dir => $files) {
            $warnings[] = sprintf(
                '%d ARWs in %s are not in any archive',
                count($files),
                $dir,
            );
        }

        // Count ARWs in directories with archives as "skipped"
        $arwsSkipped = count($arwsToRemove);

        return new ArchiveVerificationResult(
            arwsToRemove: $arwsToRemove,
            unarchivedArws: $unarchivedByDir,
            warnings: $warnings,
            arwsFound: $arwsFound,
            arwsSkipped: $arwsSkipped,
            archiveReplacementSize: $archiveReplacementSize,
        );
    }

    /** @return array<string, true> Filename => true map */
    private function getArchivedFilesInDir(string $dir): array
    {
        $archives = glob($dir . DIRECTORY_SEPARATOR . 'raws-*.tar.xz');
        if ($archives === false || $archives === []) {
            return [];
        }

        $archivedFiles = [];
        foreach ($archives as $archive) {
            if (preg_match('/raws-\d+\.tar\.xz$/', $archive) !== 1) {
                continue;
            }

            $cmd = sprintf(
                '%s -tf %s 2>/dev/null',
                escapeshellarg($this->tarPath),
                escapeshellarg($archive),
            );

            $result = [];
            exec($cmd, $result, $exitCode);

            if ($exitCode !== 0) {
                continue;
            }

            foreach ($result as $filename) {
                $archivedFiles[trim($filename)] = true;
            }
        }

        return $archivedFiles;
    }

    private function getArchiveSizeInDir(string $dir): int
    {
        $archives = glob($dir . DIRECTORY_SEPARATOR . 'raws-*.tar.xz');
        if ($archives === false || $archives === []) {
            return 0;
        }

        $totalSize = 0;
        foreach ($archives as $archive) {
            if (preg_match('/raws-\d+\.tar\.xz$/', $archive) !== 1) {
                continue;
            }

            $totalSize += (int) filesize($archive);
        }

        return $totalSize;
    }
}
