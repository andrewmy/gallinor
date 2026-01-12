<?php

declare(strict_types=1);

namespace App\Images\Domain;

use App\Shared\Domain\FilesystemScanner;
use Symfony\Component\Console\Output\OutputInterface;

use function array_any;
use function array_filter;
use function count;
use function dirname;
use function glob;
use function in_array;
use function preg_match;
use function sprintf;
use function strtolower;

use const DIRECTORY_SEPARATOR;

final readonly class ImageFileCollector
{
    public function __construct(
        private FilesystemScanner $scanner,
        private Exiftool $exiftool,
    ) {
    }

    /** @param list<string> $directories */
    public function collectFromDirectories(
        array $directories,
        OutputInterface $output,
        AvifFilter $filter,
    ): ImageCollection {
        $jpegs     = [];
        $arwsByDir = [];
        $skipSet   = [];
        $stats     = new ImageCollectionStats();

        /** @var array<string, true> $processedDirs */
        $processedDirs = [];

        foreach ($this->scanner->scanDirectories($directories) as $file) {
            $filePath  = $file->getPathname();
            $extension = strtolower($file->getExtension());
            $dir       = dirname($filePath);

            if (! isset($processedDirs[$dir])) {
                $processedDirs[$dir] = true;
                $found               = $this->exiftool->findPortraitAndLivePhotos($dir);
                if ($found !== []) {
                    $output->writeln(sprintf('  Found %d Portrait/Live Photos in: %s', count($found), $dir));
                    $skipSet += $found;
                }
            }

            if (! in_array($extension, ['jpg', 'jpeg', 'arw'], true)) {
                continue;
            }

            if (in_array($extension, ['jpg', 'jpeg'], true)) {
                $stats = $stats->addJpegsFound();

                if (isset($skipSet[$filePath])) {
                    $output->writeln(sprintf('  Skipping (Portrait/Live): %s', $filePath));
                    $stats = $stats->addJpegsSkipped();
                    continue;
                }

                $imageFile = new ImageFile($filePath, $file->getSize());

                if ($filter === AvifFilter::OnlyWith && ! $imageFile->hasOptimized()) {
                    $output->writeln(sprintf('  Skipping (no AVIF): %s', $filePath));
                    $stats = $stats->addJpegsSkipped();
                    continue;
                }

                if ($filter === AvifFilter::OnlyWithout && $imageFile->hasOptimized()) {
                    $output->writeln(sprintf('  Skipping (AVIF exists): %s', $filePath));
                    $stats = $stats->addJpegsSkipped();
                    continue;
                }

                $jpegs[] = $imageFile;
                continue;
            }

            $stats = $stats->addArwsFound();

            if (! isset($arwsByDir[$dir])) {
                // Skip ARWs in directories that already have an archive
                if ($this->archiveExistsInDir($dir)) {
                    $output->writeln(sprintf('  Skipping ARWs in dir (archive exists): %s', $dir));
                    $arwsByDir[$dir] = []; // Mark as processed but empty
                    continue;
                }

                $arwsByDir[$dir] = [];
            }

            if ($arwsByDir[$dir] !== []) {
                $arwsByDir[$dir][] = $filePath;
            } elseif (! $this->archiveExistsInDir($dir)) {
                $arwsByDir[$dir][] = $filePath;
            }
        }

        $arwsByDir = array_filter($arwsByDir, static fn (array $files) => $files !== []);

        return new ImageCollection(
            jpegs: $jpegs,
            arwsByDir: $arwsByDir,
            skipSet: $skipSet,
            stats: $stats,
        );
    }

    private function archiveExistsInDir(string $dir): bool
    {
        $files = glob($dir . DIRECTORY_SEPARATOR . 'raws-*.tar.xz');

        if ($files === false) {
            return false;
        }

        return array_any($files, static fn (string $file): bool => preg_match('/raws-\d+\.tar\.xz$/', $file) === 1);
    }
}
