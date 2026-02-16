<?php

declare(strict_types=1);

namespace App\Images\Ui\Cli;

use App\Images\Domain\ArchiveVerifier;
use App\Images\Domain\Exiftool;
use App\Images\Domain\ImageFileCollector;
use App\Images\Domain\ImageFormat;
use App\Images\Domain\OptimizedFilter;
use App\Shared\Domain\FilesystemScanner;
use App\Shared\Domain\Platform;
use App\Shared\Infrastructure\RealProcessExecutor;
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

#[AsCommand(name: 'images:remove-originals', description: 'Remove original JPEGs and ARWs after conversion/archiving')]
final class RemoveOriginals extends Command
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly CliHelper $cliHelper,
        private readonly FilesystemScanner $scanner,
        private readonly Timing $timing,
        private readonly Platform $platform,
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
        $startTime   = $this->cliHelper->startCommand($output, $dryRun, $this->timing);
        $imageFormat = ImageFormat::Heic;

        try {
            $exiftool        = new Exiftool($this->platform);
            $collector       = new ImageFileCollector($this->scanner, $exiftool);
            $archiveVerifier = new ArchiveVerifier($this->platform, new RealProcessExecutor());
        } catch (Throwable $exception) {
            $output->writeln(sprintf('<error>%s</error>', $exception->getMessage()));

            return self::FAILURE;
        }

        $jpegCollection = $collector->collectFromDirectories(
            $directories,
            $output,
            $imageFormat,
            OptimizedFilter::OnlyWith,
        );

        $verificationResult = $archiveVerifier->verify(
            $this->scanner->scanDirectories($directories),
            fn (string $path) => $output->writeln(sprintf('  Will remove: %s', $this->cliHelper->link($path))),
        );

        $gatherTime = microtime(true);
        $output->writeln(sprintf('<info>Gather time: %.3fs</info>', $gatherTime - $startTime));

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
        $heicReplacementSize = 0;
        foreach ($jpegCollection->jpegs as $imageFile) {
            $jpegSpaceToFree     += $imageFile->size;
            $heicReplacementSize += (int) filesize($imageFile->optimizedPathFor($imageFormat));
        }

        $arwSpaceToFree = 0;
        foreach ($arwsToRemove as $path) {
            $arwSpaceToFree += (int) filesize($path);
        }

        if ($dryRun) {
            $this->printJpegSummary(
                $output,
                found: $jpegCollection->stats->jpegsFound,
                skipped: $jpegCollection->stats->jpegsSkipped,
                willFreeSize: $jpegSpaceToFree,
                replacementSize: $heicReplacementSize,
            );

            $this->printArwSummary(
                $output,
                found: $arwStats['arwsFound'],
                skipped: $arwStats['arwsSkipped'],
                notArchived: $arwStats['arwsNotArchived'],
                willFreeSize: $arwSpaceToFree,
                replacementSize: $arwStats['archiveReplacementSize'],
            );

            $output->writeln('');
            $this->timing
                ->withGather($gatherTime - $startTime)
                ->withTotal($this->timing->initSeconds() + $gatherTime - $startTime)
                ->print($output);

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
        $heicReplacementSize = 0;
        foreach ($jpegCollection->jpegs as $imageFile) {
            $heicReplacementSize += (int) filesize($imageFile->optimizedPathFor($imageFormat));
        }

        $this->printJpegSummary(
            $output,
            found: $jpegCollection->stats->jpegsFound,
            skipped: $jpegCollection->stats->jpegsSkipped,
            removed: $jpegsRemoved,
            errored: $jpegsErrored,
            freedSize: $jpegSpaceFreed,
            replacementSize: $heicReplacementSize,
        );

        $this->printArwSummary(
            $output,
            found: $arwStats['arwsFound'],
            skipped: $arwStats['arwsSkipped'],
            notArchived: $arwStats['arwsNotArchived'],
            removed: $arwsRemoved,
            errored: $arwsErrored,
            freedSize: $arwSpaceFreed,
            replacementSize: $arwStats['archiveReplacementSize'],
        );

        $output->writeln('');
        $this->timing
            ->withGather($gatherTime - $startTime)
            ->withRemove($endTime - $gatherTime)
            ->withTotal($this->timing->initSeconds() + $endTime - $startTime)
            ->print($output);

        return self::SUCCESS;
    }

    private function printJpegSummary(
        OutputInterface $output,
        int $found,
        int|null $skipped = null,
        int|null $removed = null,
        int|null $errored = null,
        int|null $willFreeSize = null,
        int|null $freedSize = null,
        int|null $replacementSize = null,
    ): void {
        $output->writeln('');
        $output->writeln('JPEG Summary:');
        $output->writeln(sprintf('  Found: %d', $found));

        if ($skipped !== null) {
            $output->writeln(sprintf('  Skipped: %d', $skipped));
        }

        if ($removed !== null) {
            $output->writeln(sprintf('  Removed: %d', $removed));
        }

        if ($errored !== null) {
            $output->writeln(sprintf('  Errored: %d', $errored));
        }

        if ($willFreeSize !== null) {
            $output->writeln(sprintf('  Will free: %s', $this->cliHelper->formatBytes($willFreeSize)));
        }

        if ($freedSize !== null) {
            $output->writeln(sprintf('  Freed: %s', $this->cliHelper->formatBytes($freedSize)));
        }

        if ($replacementSize === null) {
            return;
        }

        $output->writeln(sprintf('  Replacement: %s', $this->cliHelper->formatBytes($replacementSize)));
        $savings = ($willFreeSize ?? $freedSize ?? 0) - $replacementSize;
        $output->writeln(sprintf('  Savings: %s', $this->cliHelper->formatBytes($savings)));
    }

    private function printArwSummary(
        OutputInterface $output,
        int $found,
        int|null $archived = null,
        int|null $skipped = null,
        int|null $notArchived = null,
        int|null $removed = null,
        int|null $errored = null,
        int|null $willFreeSize = null,
        int|null $freedSize = null,
        int|null $replacementSize = null,
    ): void {
        $output->writeln('');
        $output->writeln('ARW Summary:');
        $output->writeln(sprintf('  Found: %d', $found));

        if ($archived !== null) {
            $output->writeln(sprintf('  Archived: %d', $archived));
        }

        if ($skipped !== null) {
            $output->writeln(sprintf('  Skipped: %d', $skipped));
        }

        if ($notArchived !== null) {
            $output->writeln(sprintf('  Not archived: %d', $notArchived));
        }

        if ($removed !== null) {
            $output->writeln(sprintf('  Removed: %d', $removed));
        }

        if ($errored !== null) {
            $output->writeln(sprintf('  Errored: %d', $errored));
        }

        if ($willFreeSize !== null) {
            $output->writeln(sprintf('  Will free: %s', $this->cliHelper->formatBytes($willFreeSize)));
        }

        if ($freedSize !== null) {
            $output->writeln(sprintf('  Freed: %s', $this->cliHelper->formatBytes($freedSize)));
        }

        if ($replacementSize === null) {
            return;
        }

        $output->writeln(sprintf('  Archive replacement: %s', $this->cliHelper->formatBytes($replacementSize)));
        $savings = ($willFreeSize ?? $freedSize ?? 0) - $replacementSize;
        $output->writeln(sprintf('  Savings: %s', $this->cliHelper->formatBytes($savings)));
    }
}
