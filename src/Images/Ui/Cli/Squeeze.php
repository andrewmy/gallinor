<?php

declare(strict_types=1);

namespace App\Images\Ui\Cli;

use App\Images\Domain\AvifFilter;
use App\Images\Domain\CalculationSkipReason;
use App\Images\Domain\CqLevelCalculator;
use App\Images\Domain\Exiftool;
use App\Images\Domain\ImageFile;
use App\Images\Domain\ImageFileCollector;
use App\Images\Domain\ImageProcessingResult;
use App\Images\Domain\ImageTools;
use App\Images\Domain\RawArchiver;
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
use function filesize;
use function microtime;
use function sprintf;

use const PHP_EOL;

#[AsCommand(name: 'images:squeeze', description: 'Re-encode JPEGs to optimal AVIFs, XZ the ARWs')]
final class Squeeze extends Command
{
    private Platform $platform;
    private ImageTools $imageTools;
    private CqLevelCalculator $cqCalculator;
    private Exiftool $exiftool;
    private RawArchiver $rawArchiver;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly CliHelper $cliHelper,
        private readonly ImageFileCollector $collector,
        private readonly Timing $timing,
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

        try {
            $this->platform     = new Platform();
            $this->imageTools   = new ImageTools($this->platform);
            $this->cqCalculator = new CqLevelCalculator($this->imageTools);

            $output->writeln(sprintf('<info>Found avifenc: %s</info>', $this->imageTools->avifencPath));
            $output->writeln(sprintf('<info>Found avifdec: %s</info>', $this->imageTools->avifdecPath));
            $output->writeln(sprintf('<info>Found ssimulacra2: %s</info>', $this->imageTools->ssimulacra2Path));

            $xzPath            = $this->validateArchiveTools($output);
            $this->rawArchiver = new RawArchiver($this->platform, $xzPath, $this->logger);
            $this->exiftool    = new Exiftool($this->platform);
            $output->writeln(sprintf('<info>Found exiftool: %s</info>', $this->exiftool->path()));
        } catch (Throwable $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');

            return self::FAILURE;
        }

        $output->writeln(sprintf('<info>Available cores: %d</info>', $this->platform->nCores));
        $output->writeln('');

        $totalJpegsProcessed = 0;
        $totalJpegsSkipped   = 0;
        $totalJpegsErrored   = 0;
        $totalJpegSizeBefore = 0;
        $totalJpegSizeAfter  = 0;
        $totalArwsArchived   = 0;
        $totalArwSizeBefore  = 0;
        $totalArchiveSize    = 0;
        $totalQcTime         = 0.0;

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

        $jpegCount   = count($collection->jpegs);
        $progressBar = $this->cliHelper->createProgressBar($output, $jpegCount, 'JPEGs');
        $progressBar->start();

        $totalSavings = 0;

        $cliHelper = $this->cliHelper;

        foreach ($collection->jpegs as $imageFile) {
            $fileName = $imageFile->filename();
            $progressBar->setMessage($fileName, 'status');
            $progressBar->display();

            $statusCallback = static function (int $cqLevel, float $score, int $saved) use ($progressBar, $fileName, &$totalSavings, $cliHelper): void {
                $runningTotal = $totalSavings + $saved;
                $progressBar->setMessage(
                    sprintf('%s | cq=%d, score=%.1f, saved %s (total: %s)', $fileName, $cqLevel, $score, $cliHelper->formatBytes($saved), $cliHelper->formatBytes($runningTotal)),
                    'status',
                );
                $progressBar->display();
            };

            try {
                $totalJpegSizeBefore += $imageFile->size;

                $result = $this->processJpeg($imageFile, $output, $statusCallback);

                if ($result instanceof CalculationSkipReason) {
                    $totalJpegsSkipped++;
                    $progressBar->clear();
                    $output->write('<comment>Skipped: </comment>');
                    $output->write($this->cliHelper->link($imageFile->path));
                    $output->writeln(sprintf(' <comment>(%s)</comment>', $result->value));
                    $progressBar->display();
                    $progressBar->advance();
                    continue;
                }

                $totalJpegSizeAfter += $result->avifSize;
                $totalQcTime        += $result->qcTime;
                $totalJpegsProcessed++;

                $savings       = $result->savings($imageFile->size);
                $totalSavings += $savings;
                $progressBar->setMessage(
                    sprintf('%s | cq=%d, score=%.1f, saved %s (total: %s)', $fileName, $result->cqLevel, $result->qualityScore, $cliHelper->formatBytes($savings), $cliHelper->formatBytes($totalSavings)),
                    'status',
                );

                $this->logger->info('Processed JPEG', [
                    'original_file' => $imageFile->path,
                    'original_size' => $imageFile->size,
                    'avif_file' => $imageFile->optimizedPath(),
                    'avif_size' => $result->avifSize,
                    'cq_level' => $result->cqLevel,
                    'quality_score' => $result->qualityScore,
                ]);
            } catch (Throwable $exception) {
                $progressBar->setMessage(sprintf('%s | <error>Error</error>', $fileName), 'status');
                $progressBar->clear();
                $output->writeln(sprintf('<error>%s: %s</error>', $fileName, $exception->getMessage()));
                $progressBar->display();

                $totalJpegsErrored++;
            }

            $progressBar->advance();
        }

        $progressBar->setMessage('Done', 'status');
        $progressBar->finish();
        $output->writeln('');

        $archiveStartTime = microtime(true);
        $arwDirCount      = count($collection->arwsByDir);

        if ($arwDirCount > 0) {
            $arwProgressBar = $this->cliHelper->createProgressBar($output, $arwDirCount, 'ARW dirs');
            $arwProgressBar->start();

            foreach ($collection->arwsByDir as $dir => $arwFiles) {
                $dirName   = basename($dir);
                $fileCount = count($arwFiles);
                $arwProgressBar->setMessage(sprintf('%s (%d files)', $dirName, $fileCount), 'status');
                $arwProgressBar->display();

                $arwDirSizeBefore = 0;
                foreach ($arwFiles as $arwFile) {
                    $arwDirSizeBefore += filesize($arwFile);
                }

                $totalArwSizeBefore += $arwDirSizeBefore;

                try {
                    $archiveSize        = $this->rawArchiver->archive($dir, $arwFiles);
                    $totalArchiveSize  += $archiveSize;
                    $totalArwsArchived += $fileCount;

                    $arwProgressBar->setMessage(
                        sprintf('%s | %s', $dirName, $cliHelper->formatBytes($archiveSize)),
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
        }

        $totalArchiveTime = microtime(true) - $archiveStartTime;

        $endTime = microtime(true);

        $output->writeln('');
        new JpegSummary(
            found: $collection->stats->jpegsFound,
            processed: $totalJpegsProcessed,
            skipped: $collection->stats->jpegsSkipped + $totalJpegsSkipped,
            errored: $totalJpegsErrored,
            sizeBefore: $totalJpegSizeBefore,
            sizeAfter: $totalJpegSizeAfter,
        )->print($output, $this->cliHelper);

        $output->writeln('');
        new ArwSummary(
            found: $collection->stats->arwsFound,
            archived: $totalArwsArchived,
            sizeBefore: $totalArwSizeBefore,
            sizeAfter: $totalArchiveSize,
        )->print($output, $this->cliHelper);

        $output->writeln('');
        $this->timing
            ->withGather($gatherTime - $startTime)
            ->withQc($totalQcTime)
            ->withArchiving($totalArchiveTime)
            ->withTotal($this->timing->initSeconds() + $endTime - $startTime)
            ->print($output);

        return self::SUCCESS;
    }

    /** @return string Path to xz tool */
    private function validateArchiveTools(OutputInterface $output): string
    {
        $requiredTools = ['xz', 'tar'];
        $xzPath        = '';

        foreach ($requiredTools as $tool) {
            $path = $this->platform->findTool($tool);
            $output->writeln(sprintf('<info>Found %s: %s</info>', $tool, $path));

            if ($tool !== 'xz') {
                continue;
            }

            $xzPath = $path;
        }

        return $xzPath;
    }

    /** @param callable(int, float, int): void $statusCallback Called with (cqLevel, score, saved) during quality search */
    private function processJpeg(ImageFile $file, OutputInterface $output, callable $statusCallback): ImageProcessingResult|CalculationSkipReason
    {
        if ($output->isVerbose()) {
            $output->writeln(sprintf('  <comment>Searching for optimal CQ level...</comment>'));
        }

        $result = $this->cqCalculator->calculate($file, $statusCallback);

        if ($result instanceof ImageProcessingResult && $output->isVerbose()) {
            $output->writeln(sprintf('  <info>Found optimal: cq-level=%d, score=%.2f</info>', $result->cqLevel, $result->qualityScore));
        }

        return $result;
    }
}
