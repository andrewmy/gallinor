<?php

declare(strict_types=1);

namespace App\Images\Ui\Cli;

use App\Images\Domain\CalculationSkipReason;
use App\Images\Domain\Exiftool;
use App\Images\Domain\FfmpegImageNormalizer;
use App\Images\Domain\HeicCodec;
use App\Images\Domain\ImageCollection;
use App\Images\Domain\ImageFile;
use App\Images\Domain\ImageFileCollector;
use App\Images\Domain\ImageFormat;
use App\Images\Domain\ImageOptimizer;
use App\Images\Domain\OptimizedFilter;
use App\Images\Domain\RawArchiver;
use App\Images\Domain\Ssimulacra2;
use App\Images\Domain\StrictMetadataVerifier;
use App\Images\Ui\Cli\Parallel\ParallelExecutionPlanResolver;
use App\Images\Ui\Cli\Parallel\ParallelJpegProcessor;
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

use function basename;
use function count;
use function dirname;
use function filesize;
use function microtime;
use function sprintf;

use const DIRECTORY_SEPARATOR;

#[AsCommand(name: 'images:squeeze', description: 'Re-encode JPEGs to optimal HEICs, XZ the ARWs')]
final class Squeeze extends Command
{
    public function __construct(
        private readonly CliHelper $cliHelper,
        private readonly FilesystemScanner $scanner,
        private readonly Timing $timing,
        private readonly Platform $platform,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    /** @param list<string> $paths */
    public function __invoke(
        OutputInterface $output,
        #[Option]
        bool $dryRun = false,
        #[Option(description: 'Enable worker pool for JPEG processing')]
        bool $parallel = false,
        #[Option(description: 'Fixed parallel worker count override')]
        int|null $concurrency = null,
        #[Option(description: 'Adaptive max worker count: start from safe workers and ramp up to this max')]
        int|null $adaptiveConcurrency = null,
        #[Option(description: 'Recycle a worker after N JPEG jobs')]
        int $workerMaxJobs = 50,
        #[Option(description: 'Job inactivity timeout in seconds (0 disables)')]
        int $jobTimeout = 3600,
        #[Argument]
        array $paths = [],
    ): int {
        $startTime = $this->cliHelper->startCommand($output, $dryRun, $this->timing);

        $validationError = ParallelExecutionPlanResolver::validationError(
            concurrency: $concurrency,
            adaptiveConcurrency: $adaptiveConcurrency,
            workerMaxJobs: $workerMaxJobs,
            jobTimeout: $jobTimeout,
        );
        if ($validationError !== null) {
            $output->writeln(sprintf('<error>%s</error>', $validationError));

            return self::FAILURE;
        }

        try {
            $exiftool    = new Exiftool($this->platform);
            $collector   = new ImageFileCollector($this->scanner, $exiftool);
            $ssim        = Ssimulacra2::fromPlatform($this->platform);
            $normalizer  = new FfmpegImageNormalizer($this->platform, $exiftool);
            $verifier    = new StrictMetadataVerifier();
            $optimizer   = new ImageOptimizer($ssim, $normalizer, $exiftool, $verifier);
            $processExec = new RealProcessExecutor();
            $rawArchiver = new RawArchiver($this->platform, $this->logger, $processExec);
            $codec       = new HeicCodec($this->platform);
        } catch (Throwable $exception) {
            $output->writeln(sprintf('<error>%s</error>', $exception->getMessage()));

            return self::FAILURE;
        }

        $output->writeln('<info>Found: exiftool, ffmpeg, ssimulacra2, xz, tar, heic</info>');
        $output->writeln(sprintf('<info>Available cores: %d</info>', $this->platform->nCores()));

        $effectiveConcurrency = 1;
        $adaptiveStartWorkers = null;
        if ($parallel) {
            $parallelPlan         = ParallelExecutionPlanResolver::resolve(
                nCores: $this->platform->nCores(),
                concurrency: $concurrency,
                adaptiveConcurrency: $adaptiveConcurrency,
            );
            $effectiveConcurrency = $parallelPlan->workers;
            $adaptiveStartWorkers = $parallelPlan->adaptiveStartWorkers;

            $output->writeln(sprintf(
                '<info>%s</info>',
                $parallelPlan->enabledMessage('JPEG', $workerMaxJobs, $jobTimeout),
            ));
        } else {
            $output->writeln('<info>Parallel JPEG mode: disabled</info>');
        }

        $output->writeln('');

        $collection = $collector->collectFromDirectories(
            $paths,
            $output,
            ImageFormat::Heic,
            OptimizedFilter::OnlyWithout,
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

        $jpegResult = $parallel
            ? $this->processJpegsParallel(
                $output,
                $collection->jpegs,
                $effectiveConcurrency,
                $workerMaxJobs,
                $jobTimeout,
                $adaptiveStartWorkers,
            )
            : $this->processJpegs($output, $collection->jpegs, $optimizer, $codec);

        $archivingStart = microtime(true);
        $arwResult      = $this->archiveArws($output, $collection, $rawArchiver);
        $archivingTime  = microtime(true) - $archivingStart;

        $endTime = microtime(true);

        $this->printSummaries(
            $output,
            $collection,
            $jpegResult,
            $arwResult,
            $startTime,
            $gatherTime,
            $endTime,
            $archivingTime,
        );

        return self::SUCCESS;
    }

    /** @param array<ImageFile> $jpegs */
    private function processJpegs(OutputInterface $output, array $jpegs, ImageOptimizer $optimizer, HeicCodec $codec): ImageBatchResult
    {
        $progressBar = $this->cliHelper->createProgressBar($output, count($jpegs), 'JPEGs');
        $progressBar->start();

        $totalSavings = 0;
        $cliHelper    = $this->cliHelper;

        $result              = new ImageBatchResult();
        $statusEventCallback = static function (
            string $phase,
            int|null $quality,
            float|null $score,
            int|null $savedBytes,
            string|null $decision,
        ): void {
        };

        foreach ($jpegs as $image) {
            $fileName = $image->filename();
            $progressBar->setMessage($fileName, 'status');
            $progressBar->display();

            $statusCallback = static function (int $cqLevel, float $score, int $saved) use ($progressBar, &$totalSavings, $cliHelper, $fileName, $codec): void {
                $runningTotal = $totalSavings + $saved;
                $progressBar->setMessage(
                    sprintf('%s | %s=%d, score=%.1f, saved %s (total: %s)', $fileName, $codec->qualityLabel(), $cqLevel, $score, $cliHelper->formatBytes($saved), $cliHelper->formatBytes($runningTotal)),
                    'status',
                );
                $progressBar->display();
            };

            try {
                $outcome = $optimizer->optimizeJpeg($image, $codec, $statusCallback, $statusEventCallback);

                if ($outcome instanceof CalculationSkipReason) {
                    $result->skipped[$image->path] = $outcome;
                    $progressBar->setMessage(sprintf('%s | <comment>Skipped</comment>', $fileName), 'status');
                    $progressBar->clear();
                    $output->writeln(sprintf('<comment>Skipped: %s (%s)</comment>', $fileName, $outcome->value));
                    $progressBar->display();
                    $progressBar->advance();
                    continue;
                }

                $totalSavings                   += $outcome->originalSize - $outcome->optimizedSize;
                $result->processed[$image->path] = $outcome;
            } catch (Throwable $e) {
                $result->errored[$image->path] = $e->getMessage();
                $progressBar->setMessage(sprintf('%s | <error>Error</error>', $fileName), 'status');
                $progressBar->clear();
                $output->writeln(sprintf('<error>%s: %s</error>', $image->path, $e->getMessage()));
                $progressBar->display();
            }

            $progressBar->advance();
        }

        $progressBar->setMessage('Done', 'status');
        $progressBar->finish();
        $output->writeln('');

        return $result;
    }

    /** @param list<ImageFile> $jpegs */
    private function processJpegsParallel(
        OutputInterface $output,
        array $jpegs,
        int $concurrency,
        int $workerMaxJobs,
        int $jobTimeout,
        int|null $adaptiveStartWorkers,
    ): ImageBatchResult {
        $appPath = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'app.php';

        $processor = new ParallelJpegProcessor(
            $this->cliHelper,
            $this->logger,
            $appPath,
        );

        return $processor->process(
            $output,
            $jpegs,
            $concurrency,
            $workerMaxJobs,
            $jobTimeout,
            $adaptiveStartWorkers,
        );
    }

    /** @return array{archived: int, sizeBefore: int, sizeAfter: int} */
    private function archiveArws(OutputInterface $output, ImageCollection $collection, RawArchiver $rawArchiver): array
    {
        $arwsByDir   = $collection->arwsByDir;
        $arwDirCount = count($arwsByDir);

        $archived   = 0;
        $sizeBefore = 0;
        $sizeAfter  = 0;

        if ($arwDirCount === 0) {
            return ['archived' => 0, 'sizeBefore' => 0, 'sizeAfter' => 0];
        }

        $arwProgressBar = $this->cliHelper->createProgressBar($output, $arwDirCount, 'ARW dirs');
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
                $archiveSize = $rawArchiver->archive($dir, $arwFiles);
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
        float $archivingTime,
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
            ->withArchiving($archivingTime)
            ->withTotal($this->timing->initSeconds() + $endTime - $startTime)
            ->print($output);
    }
}
