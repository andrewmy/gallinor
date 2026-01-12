<?php

declare(strict_types=1);

namespace App\Images\Ui\Cli;

use App\Images\Domain\AvifFilter;
use App\Images\Domain\Exiftool;
use App\Images\Domain\ImageFileCollector;
use App\Shared\Domain\FilesystemScanner;
use App\Shared\Domain\Platform;
use App\Shared\Ui\Cli\CliHelper;
use App\Shared\Ui\Cli\Timing;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

use function basename;
use function count;
use function dirname;
use function escapeshellarg;
use function exec;
use function filesize;
use function glob;
use function microtime;
use function preg_match;
use function sprintf;
use function strtolower;
use function trim;
use function unlink;

use const DIRECTORY_SEPARATOR;
use const PHP_EOL;

#[AsCommand(name: 'images:remove-originals', description: 'Remove original JPEGs and ARWs after conversion/archiving')]
final class RemoveOriginals extends Command
{
    private Platform $platform;
    private Exiftool $exiftool;

    /** @var array<string, string> */
    private array $toolPaths = [];

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly CliHelper $cliHelper,
        private readonly FilesystemScanner $scanner,
        private readonly ImageFileCollector $collector,
    ) {
        parent::__construct();
    }

    /** @param list<string> $directories */
    public function __invoke(
        OutputInterface $output,
        #[Option]
        bool $dryRun = false,
        #[Argument]
        array $directories = [],
    ): int {
        $output->writeln(sprintf('<info>Dry run: %s</info>%s', $dryRun ? 'Yes' : 'No', PHP_EOL));

        $startTime = microtime(true);

        try {
            $this->platform = new Platform();
            $this->validateTools($output);
            $this->exiftool = new Exiftool($this->platform);
            $output->writeln(sprintf('<info>Found exiftool: %s</info>', $this->exiftool->path()));
        } catch (Throwable $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');

            return self::FAILURE;
        }

        $initTime = microtime(true);
        $output->writeln(sprintf('<info>Init time: %.3fs</info>', $initTime - $startTime));
        $output->writeln('');

        $jpegCollection = $this->collector->collectFromDirectories(
            $directories,
            $output,
            AvifFilter::OnlyWith,
        );

        [$arwsToRemove, $arwWarnings, $arwStats] = $this->verifyArchivedArws($directories, $output);

        $gatherTime = microtime(true);
        $output->writeln(sprintf('<info>Gather time: %.3fs</info>', $gatherTime - $initTime));

        foreach ($arwWarnings as $dir => $files) {
            $output->writeln(sprintf(
                '<comment>Warning: %d ARWs in %s are not in any archive</comment>',
                count($files),
                $this->cliHelper->link($dir),
            ));
        }

        $jpegSpaceToFree     = 0;
        $avifReplacementSize = 0;
        foreach ($jpegCollection->jpegs as $imageFile) {
            $jpegSpaceToFree     += $imageFile->size;
            $avifReplacementSize += (int) filesize($imageFile->optimizedPath());
        }

        $arwSpaceToFree = 0;
        foreach ($arwsToRemove as $path) {
            $arwSpaceToFree += (int) filesize($path);
        }

        if ($dryRun) {
            $output->writeln('');
            new JpegSummary(
                found: $jpegCollection->stats->jpegsFound,
                skipped: $jpegCollection->stats->jpegsSkipped,
                removedSize: $jpegSpaceToFree,
                replacementSize: $avifReplacementSize,
            )->print($output, $this->cliHelper);

            $output->writeln('');
            new ArwSummary(
                found: $arwStats['arwsFound'],
                skipped: $arwStats['arwsSkipped'],
                notArchived: $arwStats['arwsNotArchived'],
                removedSize: $arwSpaceToFree,
                replacementSize: $arwStats['archiveReplacementSize'],
            )->print($output, $this->cliHelper);

            $output->writeln('');
            new Timing(
                total: $gatherTime - $startTime,
                init: $initTime - $startTime,
                gather: $gatherTime - $initTime,
            )->print($output);

            return self::SUCCESS;
        }

        $jpegsRemoved   = 0;
        $jpegSpaceFreed = 0;
        $jpegsErrored   = 0;

        foreach ($jpegCollection->jpegs as $imageFile) {
            try {
                $size = $imageFile->size;
                unlink($imageFile->path);
                $jpegsRemoved++;
                $jpegSpaceFreed += $size;

                $this->logger->info('Removed JPEG', ['file' => $imageFile->path, 'size' => $size]);
            } catch (Throwable $exception) {
                $output->writeln(sprintf('<error>Failed to remove %s: %s</error>', $imageFile->path, $exception->getMessage()));
                $jpegsErrored++;
            }
        }

        $arwsRemoved   = 0;
        $arwSpaceFreed = 0;
        $arwsErrored   = 0;

        foreach ($arwsToRemove as $arwPath) {
            try {
                $size = (int) filesize($arwPath);
                unlink($arwPath);
                $arwsRemoved++;
                $arwSpaceFreed += $size;

                $this->logger->info('Removed ARW', ['file' => $arwPath, 'size' => $size]);
            } catch (Throwable $exception) {
                $output->writeln(sprintf('<error>Failed to remove %s: %s</error>', $arwPath, $exception->getMessage()));
                $arwsErrored++;
            }
        }

        $endTime = microtime(true);

        // actual removal summary
        $avifReplacementSize = 0;
        foreach ($jpegCollection->jpegs as $imageFile) {
            $avifReplacementSize += (int) filesize($imageFile->optimizedPath());
        }

        $output->writeln('');
        new JpegSummary(
            found: $jpegCollection->stats->jpegsFound,
            skipped: $jpegCollection->stats->jpegsSkipped,
            removed: $jpegsRemoved,
            errored: $jpegsErrored,
            removedSize: $jpegSpaceFreed,
            replacementSize: $avifReplacementSize,
        )->print($output, $this->cliHelper);

        $output->writeln('');
        new ArwSummary(
            found: $arwStats['arwsFound'],
            skipped: $arwStats['arwsSkipped'],
            notArchived: $arwStats['arwsNotArchived'],
            removed: $arwsRemoved,
            errored: $arwsErrored,
            removedSize: $arwSpaceFreed,
            replacementSize: $arwStats['archiveReplacementSize'],
        )->print($output, $this->cliHelper);

        $output->writeln('');
        new Timing(
            total: $endTime - $startTime,
            init: $initTime - $startTime,
            gather: $gatherTime - $initTime,
            remove: $endTime - $gatherTime,
        )->print($output);

        return self::SUCCESS;
    }

    private function validateTools(OutputInterface $output): void
    {
        $requiredTools = ['tar'];

        foreach ($requiredTools as $tool) {
            $this->toolPaths[$tool] = $this->platform->findTool($tool);
            $output->writeln(sprintf('<info>Found %s: %s</info>', $tool, $this->toolPaths[$tool]));
        }
    }

    /**
     * @param list<string> $directories
     *
     * @return array{list<string>, array<string, list<string>>, array{arwsFound: int, arwsSkipped: int, arwsNotArchived: int, archiveReplacementSize: int}}
     */
    private function verifyArchivedArws(array $directories, OutputInterface $output): array
    {
        $arwsToRemove = [];
        $arwWarnings  = [];
        $stats        = [
            'arwsFound' => 0,
            'arwsNotArchived' => 0,
            'archiveReplacementSize' => 0,
        ];

        /** @var array<string, array<string, true>> $archivedFilesCache */
        $archivedFilesCache = [];

        /** @var array<string, true> $archiveSizesCounted */
        $archiveSizesCounted = [];

        foreach ($this->scanner->scanDirectories($directories) as $file) {
            $filePath  = $file->getPathname();
            $extension = strtolower($file->getExtension());
            $dir       = dirname($filePath);

            if ($extension !== 'arw') {
                continue;
            }

            $stats['arwsFound']++;

            if (! isset($archivedFilesCache[$dir])) {
                $archivedFilesCache[$dir] = $this->getArchivedFilesInDir($dir);
            }

            $filename = basename($filePath);
            if (! isset($archivedFilesCache[$dir][$filename])) {
                $stats['arwsNotArchived']++;
                if (! isset($arwWarnings[$dir])) {
                    $arwWarnings[$dir] = [];
                }

                $arwWarnings[$dir][] = $filePath;
                continue;
            }

            $arwsToRemove[] = $filePath;

            if (! isset($archiveSizesCounted[$dir])) {
                $archiveSizesCounted[$dir]        = true;
                $stats['archiveReplacementSize'] += $this->getArchiveSizeInDir($dir);
            }

            $output->writeln(sprintf('  Will remove: %s', $this->cliHelper->link($filePath)));
        }

        // Count ARWs in directories with archives as "skipped" (they're archived, not removed individually)
        $stats['arwsSkipped'] = count($arwsToRemove);

        return [$arwsToRemove, $arwWarnings, $stats];
    }

    /** @return array<string, true> */
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
                escapeshellarg($this->toolPaths['tar']),
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
