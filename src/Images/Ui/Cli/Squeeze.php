<?php

declare(strict_types=1);

namespace App\Images\Ui\Cli;

use App\Images\Domain\AvifFilter;
use App\Images\Domain\CalculationSkipReason;
use App\Images\Domain\CqLevelCalculator;
use App\Images\Domain\ImageCollection;
use App\Images\Domain\ImageFile;
use App\Images\Domain\ImageFileCollector;
use App\Images\Domain\RawArchiver;
use App\Shared\Domain\Platform;
use App\Shared\Ui\Cli\CliHelper;
use App\Shared\Ui\Cli\Timing;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

use function basename;
use function count;
use function filesize;
use function microtime;
use function sprintf;

use const PHP_EOL;

#[AsCommand(name: 'images:squeeze', description: 'Re-encode JPEGs to optimal AVIFs, XZ the ARWs')]
final class Squeeze extends Command
{
    public function __construct(
        private readonly CliHelper $cliHelper,
        private readonly ImageFileCollector $collector,
        private readonly Timing $timing,
        private readonly Platform $platform,
        private readonly CqLevelCalculator $cqCalculator,
        private readonly RawArchiver $rawArchiver,
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
        $output->writeln(sprintf('<info>Init time: %s</info>%s', $this->timing->formatInit(), PHP_EOL));

        $startTime = microtime(true);

        $output->writeln('<info>Found: avifenc, avifdec, ssimulacra2, xz, tar</info>');
        $output->writeln(sprintf('<info>Available cores: %d</info>', $this->platform->nCores()));
        $output->writeln('');

        $collection = $this->collector->collectFromDirectories(
            $directories,
            $output,
            AvifFilter::OnlyWithout,
        );

        $gatherTime = microtime(true);
        $output->writeln(sprintf('<info>Gather time: %.3fs</info>', $gatherTime - $startTime));

        if ($dryRun) {
            $output->writeln(sprintf(
                "\n<info>Dry run complete. Found %d JPEGs to process, %d ARW directories to archive.</info>",
                count($collection->jpegs),
                count($collection->arwsByDir),
            ));

            return self::SUCCESS;
        }

        $jpegResult = $this->processJpegs($output, $collection->jpegs);

        $arwResult = $this->archiveArws($output, $collection);

        $endTime = microtime(true);

        $this->printSummaries($output, $collection, $jpegResult, $arwResult, $startTime, $gatherTime, $endTime);

        return self::SUCCESS;
    }

    /** @param array<ImageFile> $jpegs */
    private function processJpegs(OutputInterface $output, array $jpegs): ImageBatchResult
    {
        $progressBar = $this->cliHelper->createProgressBar($output, count($jpegs), 'JPEGs');
        $progressBar->start();

        $totalSavings = 0;
        $cliHelper    = $this->cliHelper;

        $result = new ImageBatchResult();

        foreach ($jpegs as $image) {
            $fileName = $image->filename();
            $progressBar->setMessage($fileName, 'status');
            $progressBar->display();

            $statusCallback = static function (int $cqLevel, float $score, int $saved) use ($progressBar, &$totalSavings, $cliHelper, $fileName): void {
                $runningTotal = $totalSavings + $saved;
                $progressBar->setMessage(
                    sprintf('%s | cq=%d, score=%.1f, saved %s (total: %s)', $fileName, $cqLevel, $score, $cliHelper->formatBytes($saved), $cliHelper->formatBytes($runningTotal)),
                    'status',
                );
                $progressBar->display();
            };

            try {
                $outcome = $this->cqCalculator->calculate($image, $statusCallback);

                if ($outcome instanceof CalculationSkipReason) {
                    $result->skipped[$image->path] = $outcome;
                    $progressBar->setMessage(sprintf('%s | <comment>Skipped</comment>', $fileName), 'status');
                    $progressBar->clear();
                    $output->writeln(sprintf('<comment>Skipped: %s (%s)</comment>', $fileName, $outcome->value));
                    $progressBar->display();
                    $progressBar->advance();
                    continue;
                }

                $totalSavings                   += $outcome->originalSize - $outcome->avifSize;
                $result->processed[$image->path] = $outcome;
            } catch (Throwable $e) {
                $result->errored[$image->path] = $e->getMessage();
                $progressBar->setMessage(sprintf('%s | <error>Error</error>', $fileName), 'status');
                $progressBar->clear();
                $output->writeln(sprintf('<error>%s: %s</error>', $fileName, $e->getMessage()));
                $progressBar->display();
            }

            $progressBar->advance();
        }

        $progressBar->setMessage('Done', 'status');
        $progressBar->finish();
        $output->writeln('');

        return $result;
    }

    /** @return array{archived: int, sizeBefore: int, sizeAfter: int} */
    private function archiveArws(OutputInterface $output, ImageCollection $collection): array
    {
        $arwsByDir   = $collection->arwsByDir;
        $arwDirCount = count($arwsByDir);

        $archived   = 0;
        $sizeBefore = 0;
        $sizeAfter  = 0;

        if ($arwDirCount === 0) {
            return ['archived' => 0, 'sizeBefore' => 0, 'sizeAfter' => 0];
        }

        $archiveStartTime = microtime(true);
        $arwProgressBar   = $this->cliHelper->createProgressBar($output, $arwDirCount, 'ARW dirs');
        $arwProgressBar->start();

        foreach ($arwsByDir as $dir => $arwFiles) {
            $dirName   = basename($dir);
            $fileCount = count($arwFiles);
            $arwProgressBar->setMessage(sprintf('%s (%d files)', $dirName, $fileCount), 'status');
            $arwProgressBar->display();

            $dirSizeBefore = 0;
            foreach ($arwFiles as $arwFile) {
                $dirSizeBefore += filesize($arwFile);
            }

            $sizeBefore += $dirSizeBefore;

            try {
                $archiveSize = $this->rawArchiver->archive($dir, $arwFiles);
                $sizeAfter  += $archiveSize;
                $archived   += $fileCount;

                $arwProgressBar->setMessage(
                    sprintf('%s | %s', $dirName, $this->cliHelper->formatBytes($archiveSize)),
                    'status',
                );
            } catch (Throwable $exception) {
                $arwProgressBar->setMessage(sprintf('%s | <error>Error</error>', $dirName), 'status');
                $arwProgressBar->clear();
                $output->writeln(sprintf('<error>%s: %s</error>', $dirName, $exception->getMessage()));
                $arwProgressBar->display();
            }

            $arwProgressBar->advance();
        }

        $arwProgressBar->setMessage('Done', 'status');
        $arwProgressBar->finish();
        $output->writeln('');

        return [
            'archived'   => $archived,
            'sizeBefore' => $sizeBefore,
            'sizeAfter'  => $sizeAfter,
        ];
    }

    /** @param array{archived: int, sizeBefore: int, sizeAfter: int} $arwResult */
    private function printSummaries(
        OutputInterface $output,
        ImageCollection $collection,
        ImageBatchResult $jpegResult,
        array $arwResult,
        float $startTime,
        float $gatherTime,
        float $endTime,
    ): void {
        $output->writeln('');

        $output->writeln('JPEG Summary:');
        $output->writeln(sprintf('  Found: %d', $collection->stats->jpegsFound));
        $output->writeln(sprintf('  Processed: %d', $jpegResult->processedCount()));
        $output->writeln(sprintf('  Skipped: %d', $collection->stats->jpegsSkipped + $jpegResult->skippedCount()));
        $output->writeln(sprintf('  Errored: %d', $jpegResult->erroredCount()));

        if ($jpegResult->processedCount() > 0) {
            $output->writeln(sprintf('  Size before: %s', $this->cliHelper->formatBytes($jpegResult->totalBytesBefore())));
            $output->writeln(sprintf('  Size after: %s', $this->cliHelper->formatBytes($jpegResult->totalBytesAfter())));
            $output->writeln(sprintf('  Savings: %s', $this->cliHelper->formatBytes($jpegResult->totalBytesSaved())));
        }

        $output->writeln('');

        $output->writeln('ARW Summary:');
        $output->writeln(sprintf('  Found: %d', $collection->stats->arwsFound));
        $output->writeln(sprintf('  Archived: %d', $arwResult['archived']));

        if ($arwResult['archived'] > 0) {
            $output->writeln(sprintf('  Size before: %s', $this->cliHelper->formatBytes($arwResult['sizeBefore'])));
            $output->writeln(sprintf('  Size after: %s', $this->cliHelper->formatBytes($arwResult['sizeAfter'])));
            $output->writeln(sprintf('  Savings: %s', $this->cliHelper->formatBytes($arwResult['sizeBefore'] - $arwResult['sizeAfter'])));
        }

        $output->writeln('');

        $this->timing
            ->withGather($gatherTime - $startTime)
            ->withQc($jpegResult->totalQcTime())
            ->withArchiving($endTime - $gatherTime - $jpegResult->totalQcTime())
            ->withTotal($this->timing->initSeconds() + $endTime - $startTime)
            ->print($output);
    }
}
