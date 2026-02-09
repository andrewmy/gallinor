<?php

declare(strict_types=1);

namespace App\Images\Ui\Cli;

use App\Images\Domain\AvifCodec;
use App\Images\Domain\CalculationSkipReason;
use App\Images\Domain\Exiftool;
use App\Images\Domain\FfmpegImageNormalizer;
use App\Images\Domain\HeicCodec;
use App\Images\Domain\ImageCodec;
use App\Images\Domain\ImageCollection;
use App\Images\Domain\ImageFile;
use App\Images\Domain\ImageFileCollector;
use App\Images\Domain\ImageFormat;
use App\Images\Domain\ImageOptimizer;
use App\Images\Domain\LibAvifTools;
use App\Images\Domain\OptimizedFilter;
use App\Images\Domain\RawArchiver;
use App\Images\Domain\Ssimulacra2;
use App\Images\Domain\StrictMetadataVerifier;
use App\Images\Ui\Cli\Parallel\ParallelConcurrency;
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

#[AsCommand(name: 'images:squeeze', description: 'Re-encode JPEGs to optimal HEICs (or AVIFs), XZ the ARWs')]
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

    /** @param list<string> $directories */
    public function __invoke(
        OutputInterface $output,
        #[Option]
        bool $dryRun = false,
        #[Option(description: 'Output format: heic (default) or avif')]
        string $format = 'heic',
        #[Option(description: 'Enable worker pool for JPEG processing')]
        bool $parallel = false,
        #[Option(description: 'Parallel worker count (defaults to auto when --parallel is enabled)')]
        int|null $concurrency = null,
        #[Option(description: 'Recycle a worker after N JPEG jobs')]
        int $workerMaxJobs = 50,
        #[Option(description: 'Job inactivity timeout in seconds (0 disables)')]
        int $jobTimeout = 3600,
        #[Argument]
        array $directories = [],
    ): int {
        $startTime = $this->cliHelper->startCommand($output, $dryRun, $this->timing);

        if ($concurrency !== null && $concurrency <= 0) {
            $output->writeln('<error>Invalid --concurrency: must be a positive integer.</error>');

            return self::FAILURE;
        }

        if ($workerMaxJobs <= 0) {
            $output->writeln('<error>Invalid --worker-max-jobs: must be a positive integer.</error>');

            return self::FAILURE;
        }

        if ($jobTimeout < 0) {
            $output->writeln('<error>Invalid --job-timeout: must be zero or a positive integer.</error>');

            return self::FAILURE;
        }

        try {
            $imageFormat = ImageFormat::fromCli($format);
        } catch (Throwable $exception) {
            $output->writeln(sprintf('<error>%s</error>', $exception->getMessage()));

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

            $codec = match ($imageFormat) {
                ImageFormat::Heic => new HeicCodec($this->platform),
                ImageFormat::Avif => new AvifCodec(LibAvifTools::fromPlatform($this->platform)),
            };
        } catch (Throwable $exception) {
            $output->writeln(sprintf('<error>%s</error>', $exception->getMessage()));

            return self::FAILURE;
        }

        $output->writeln(sprintf('<info>Found: exiftool, ffmpeg, ssimulacra2, xz, tar, %s</info>', $imageFormat->label()));
        $output->writeln(sprintf('<info>Available cores: %d</info>', $this->platform->nCores()));

        $effectiveConcurrency = null;
        if ($parallel) {
            $effectiveConcurrency = $concurrency ?? ParallelConcurrency::defaultFromCores($this->platform->nCores());

            $output->writeln(sprintf(
                '<info>Parallel JPEG mode: enabled (workers=%d, worker-max-jobs=%d, job-timeout=%ds)</info>',
                $effectiveConcurrency,
                $workerMaxJobs,
                $jobTimeout,
            ));
        } else {
            $output->writeln('<info>Parallel JPEG mode: disabled</info>');
        }

        $output->writeln('');

        $collection = $collector->collectFromDirectories(
            $directories,
            $output,
            $imageFormat,
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
                $imageFormat,
                $effectiveConcurrency,
                $workerMaxJobs,
                $jobTimeout,
            )
            : $this->processJpegs($output, $collection->jpegs, $optimizer, $codec);

        $arwResult = $this->archiveArws($output, $collection, $rawArchiver);

        $endTime = microtime(true);

        $this->printSummaries($output, $collection, $jpegResult, $arwResult, $startTime, $gatherTime, $endTime);

        return self::SUCCESS;
    }

    /** @param array<ImageFile> $jpegs */
    private function processJpegs(OutputInterface $output, array $jpegs, ImageOptimizer $optimizer, ImageCodec $codec): ImageBatchResult
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

            $statusCallback = static function (int $cqLevel, float $score, int $saved) use ($progressBar, &$totalSavings, $cliHelper, $fileName, $codec): void {
                $runningTotal = $totalSavings + $saved;
                $progressBar->setMessage(
                    sprintf('%s | %s=%d, score=%.1f, saved %s (total: %s)', $fileName, $codec->qualityLabel(), $cqLevel, $score, $cliHelper->formatBytes($saved), $cliHelper->formatBytes($runningTotal)),
                    'status',
                );
                $progressBar->display();
            };

            try {
                $outcome = $optimizer->optimizeJpeg($image, $codec, $statusCallback);

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

    /** @param list<ImageFile> $jpegs */
    private function processJpegsParallel(
        OutputInterface $output,
        array $jpegs,
        ImageFormat $format,
        int $concurrency,
        int $workerMaxJobs,
        int $jobTimeout,
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
            $format,
            $concurrency,
            $workerMaxJobs,
            $jobTimeout,
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
