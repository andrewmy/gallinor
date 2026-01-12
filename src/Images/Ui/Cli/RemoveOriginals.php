<?php

declare(strict_types=1);

namespace App\Images\Ui\Cli;

use App\Images\Domain\ArchiveVerifier;
use App\Images\Domain\AvifFilter;
use App\Images\Domain\ImageFileCollector;
use App\Shared\Domain\FilesystemScanner;
use App\Shared\Ui\Cli\CliHelper;
use App\Shared\Ui\Cli\Timing;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

use function count;
use function filesize;
use function microtime;
use function sprintf;
use function unlink;

use const PHP_EOL;

#[AsCommand(name: 'images:remove-originals', description: 'Remove original JPEGs and ARWs after conversion/archiving')]
final class RemoveOriginals extends Command
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly CliHelper $cliHelper,
        private readonly FilesystemScanner $scanner,
        private readonly ImageFileCollector $collector,
        private readonly ArchiveVerifier $archiveVerifier,
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
        $initTime  = $startTime;

        $jpegCollection = $this->collector->collectFromDirectories(
            $directories,
            $output,
            AvifFilter::OnlyWith,
        );

        $verificationResult = $this->archiveVerifier->verify(
            $this->scanner->scanDirectories($directories),
            fn (string $path) => $output->writeln(sprintf('  Will remove: %s', $this->cliHelper->link($path))),
        );

        $gatherTime = microtime(true);
        $output->writeln(sprintf('<info>Gather time: %.3fs</info>', $gatherTime - $initTime));

        foreach ($verificationResult->unarchivedArws as $dir => $files) {
            $output->writeln(sprintf(
                '<comment>Warning: %d ARWs in %s are not in any archive</comment>',
                count($files),
                $this->cliHelper->link($dir),
            ));
        }

        $arwsToRemove = $verificationResult->arwsToRemove;
        $arwStats     = $verificationResult->toStatsArray();

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
}
