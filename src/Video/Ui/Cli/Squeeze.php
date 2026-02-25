<?php

declare(strict_types=1);

namespace App\Video\Ui\Cli;

use App\Shared\Domain\FilesystemScanner;
use App\Shared\Domain\Platform;
use App\Shared\Infrastructure\RealProcessExecutor;
use App\Shared\Ui\Cli\CliHelper;
use App\Shared\Ui\Cli\Timing;
use App\Video\Domain\Encoder;
use App\Video\Domain\EncoderFactory;
use App\Video\Domain\Exceptions\UnsupportedResolution;
use App\Video\Domain\VideoFile;
use App\Video\Domain\VideoFinder;
use App\Video\Domain\VideoProcessor;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

use function array_reduce;
use function basename;
use function count;
use function explode;
use function file_exists;
use function max;
use function microtime;
use function number_format;
use function sprintf;
use function str_contains;

#[AsCommand(name: 'videos:squeeze', description: 'Re-encode videos to optimal bitrate')]
final class Squeeze extends Command
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly CliHelper $cliHelper,
        private readonly FilesystemScanner $scanner,
        private readonly Timing $timing,
        private readonly EncoderFactory $encoderFactory,
        private readonly Platform $platform,
    ) {
        parent::__construct();
    }

    private VideoFinder $videoFinder;
    private VideoProcessor $processor;
    private Encoder $encoder;

    /** @param list<string> $directories */
    public function __invoke(
        OutputInterface $output,
        #[Option]
        bool $dryRun = false,
        #[Option(description: 'Force using the CPU encoder, slow')]
        bool $useCpu = false,
        #[Option(description: 'Re-process videos even if .optimal.mp4 exists; keep the existing file when it is smaller or equal')]
        bool $recheckExistingOptimal = false,
        #[Argument]
        array $directories = [],
    ): int {
        $startTime = $this->cliHelper->startCommand($output, $dryRun, $this->timing);
        try {
            $encoder           = $this->encoderFactory->create($useCpu);
            $this->encoder     = $encoder;
            $this->videoFinder = new VideoFinder($encoder);
            $this->processor   = new VideoProcessor($encoder, $this->logger, new RealProcessExecutor());
        } catch (Throwable $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');

            return self::FAILURE;
        }

        $factoryTime = microtime(true);

        $output->writeln(sprintf('<info>Available cores: %d</info>', $this->platform->nCores()));

        try {
            $encoder->describeCapabilities(static fn (string $line) => $output->writeln(sprintf('<info>%s</info>', $line)));
        } catch (Throwable $exception) {
            $output->writeln(sprintf('<error>%s</error>', $exception->getMessage()));

            return self::FAILURE;
        }

        $output->writeln('');

        $totalProcessedSize         = 0;
        $totalErroredFiles          = 0;
        $totalQcTime                = 0;
        $totalSuccessfullyProcessed = 0;
        $totalPostProcessingSkipped = 0;

        [$fileList, $totalSkippedFiles, $totalSkippedSize] = $this->gatherFileList(
            directories: $directories,
            output: $output,
            recheckExistingOptimal: $recheckExistingOptimal,
        );

        $totalCurrentSize   = $totalSkippedSize + array_reduce(
            $fileList,
            static fn (int $carry, VideoFile $file) => $carry + $file->currentSize,
            0,
        );
        $totalProjectedSize = array_reduce(
            $fileList,
            static fn (int $carry, VideoFile $file) => $carry + $file->sizeEstimate($file->baseBitrate()),
            0,
        );

        $output->writeln(sprintf(
            "\n\nProjection:\n  Current size: %s\n  Projected size: %s\n  Projected savings: %s\n  Skipped: %d (%s)",
            $this->cliHelper->formatBytes($totalCurrentSize),
            $this->cliHelper->formatBytes($totalProjectedSize),
            $this->cliHelper->formatBytes($totalCurrentSize - $totalProjectedSize),
            $totalSkippedFiles,
            $this->cliHelper->formatBytes($totalSkippedSize),
        ));
        $gatherTime = microtime(true);
        $output->writeln(sprintf('<info>Gather time: %.3fs</info>', $gatherTime - $factoryTime));

        if ($dryRun) {
            $output->writeln('');
            $initTime = $this->timing->initSeconds() + $factoryTime - $startTime;
            $this->timing
                ->withTotal($initTime + $gatherTime - $factoryTime)
                ->print($output);

            return self::SUCCESS;
        }

        $totalSavings = 0;
        $cliHelper    = $this->cliHelper;

        $fileCount   = count($fileList);
        $progressBar = $this->cliHelper->createProgressBar($output, $fileCount, 'Videos');
        $progressBar->start();

        foreach ($fileList as $file) {
            $fileName = basename($file->path);
            $progressBar->setMessage($fileName, 'status');
            $progressBar->display();

            $progressData = [];
            $lineCallback = static function (string $line) use ($progressBar, $fileName, &$progressData): void {
                // Parse ffmpeg progress output (key=value format)
                if (! str_contains($line, '=')) {
                    return;
                }

                [$key, $value]      = explode('=', $line, 2);
                $progressData[$key] = $value;

                // Update status whenever we get frame data
                if (! isset($progressData['frame'])) {
                    return;
                }

                $progressBar->setMessage(
                    sprintf(
                        '%s | frame=%s fps=%s size=%s time=%s speed=%s',
                        $fileName,
                        $progressData['frame'],
                        $progressData['fps'] ?? 'N/A',
                        $progressData['size'] ?? 'N/A',
                        $progressData['time'] ?? 'N/A',
                        $progressData['speed'] ?? 'N/A',
                    ),
                    'status',
                );
                $progressBar->display();
            };

            $statusCallback = static function (int $bitrate, float $vmafScore, int $saved) use ($output, $progressBar, $fileName, &$totalSavings, $cliHelper): void {
                $runningTotal = $totalSavings + $saved;
                $progressBar->clear();
                $output->writeln(sprintf('%s | %sk, VMAF=%.1f, saved %s (total: %s)', $fileName, $bitrate, $vmafScore, $cliHelper->formatBytes($saved), $cliHelper->formatBytes($runningTotal)));
                $progressBar->display();
            };

            try {
                $result = $this->processor->processVideo(
                    file: $file,
                    dryRun: false,
                    statusCallback: $statusCallback,
                    lineCallback: $lineCallback,
                    keepExistingOptimalIfBetter: $recheckExistingOptimal,
                    startingBitrateKbps: $this->startingBitrateForFile($file, $recheckExistingOptimal),
                );

                if ($result->skipped) {
                    $progressBar->clear();
                    $output->write('<comment>Skipped (bitrate acceptable): </comment>');
                    $output->writeln($this->cliHelper->link($file->path));
                    $progressBar->display();
                    $totalProcessedSize += $file->currentSize;
                    $totalPostProcessingSkipped++;
                    $progressBar->advance();
                    continue;
                }

                if (! $result->success) {
                    $progressBar->setMessage(sprintf('%s | <error>Quality check failed</error>', $fileName), 'status');
                    $progressBar->clear();
                    $output->writeln(sprintf('<error>%s: Could not achieve target VMAF score</error>', $fileName));
                    $progressBar->display();
                    $totalErroredFiles++;
                    $progressBar->advance();
                    continue;
                }

                if ($result->keptExistingOptimal) {
                    $progressBar->clear();
                    $output->writeln(sprintf(
                        '<comment>%s: Existing optimal is already better, keeping %s</comment>',
                        $fileName,
                        $this->cliHelper->link($result->outputPath),
                    ));
                    $progressBar->display();
                }

                $totalProcessedSize += $result->newSize;
                $totalQcTime        += $result->qcTime;
                $totalSuccessfullyProcessed++;

                $savings       = $result->savings();
                $totalSavings += $savings;
                // Status already logged by statusCallback
            } catch (Throwable $exception) {
                $progressBar->setMessage(sprintf('%s | <error>Error</error>', $fileName), 'status');
                $progressBar->clear();
                $output->writeln(sprintf('<error>%s: %s</error>', $fileName, $exception->getMessage()));
                $progressBar->display();

                $totalErroredFiles++;
            }

            $progressBar->advance();
        }

        $progressBar->setMessage('Done', 'status');
        $progressBar->finish();
        $output->writeln('');

        $processTime = microtime(true);

        $output->writeln('');
        new VideoSummary(
            sizeBefore: $totalCurrentSize,
            sizeAfter: $totalProcessedSize,
            processed: $totalSuccessfullyProcessed,
            skipped: $totalSkippedFiles + $totalPostProcessingSkipped,
            errored: $totalErroredFiles,
        )->print($output, $this->cliHelper);

        $output->writeln('');
        $initTime = $this->timing->initSeconds() + $factoryTime - $startTime;
        $this->timing
            ->withGather($gatherTime - $factoryTime)
            ->withProcess($processTime - $gatherTime)
            ->withQc($totalQcTime)
            ->withTotal($initTime + $processTime - $factoryTime)
            ->print($output);

        return self::SUCCESS;
    }

    /**
     * @param list<string> $directories
     *
     * @return array{list<VideoFile>, int, int} Tuple of file list, total skipped files, and total skipped size
     */
    private function gatherFileList(
        array $directories,
        OutputInterface $output,
        bool $recheckExistingOptimal = false,
    ): array {
        $fileList          = [];
        $totalSkippedFiles = 0;
        $totalSkippedSize  = 0;

        $files = $this->scanner->scanDirectories($directories);

        $videos = $this->videoFinder->findVideos(
            $files,
            static function (string $filePath, string $errorMessage) use ($output, &$totalSkippedFiles): void {
                $output->writeln(sprintf('<error>%s</error>', $errorMessage));
                $totalSkippedFiles++;
            },
        );

        foreach ($videos as $videoFile) {
            $output->writeln(sprintf("\nFile: %s", $this->cliHelper->link($videoFile->path)));

            try {
                if (VideoProcessor::isBitrateAcceptable($videoFile, $videoFile->baseBitrate())) {
                    $output->writeln(sprintf('Bitrate %s Kbps is acceptable, no action needed.', $videoFile->bitRate));
                    $totalSkippedFiles++;
                    $totalSkippedSize += $videoFile->currentSize;
                    continue;
                }
            } catch (UnsupportedResolution $exception) {
                $output->writeln($exception->getMessage());
                $totalSkippedFiles++;
                $totalSkippedSize += $videoFile->currentSize;
                continue;
            }

            $optimalFilePath = $videoFile->suffixedFilePath(VideoFile::OPTIMAL_SUFFIX);
            if (file_exists($optimalFilePath) && ! $recheckExistingOptimal) {
                $output->writeln(sprintf(
                    'Optimal version already exists (%s), skipping.',
                    $this->cliHelper->link($optimalFilePath),
                ));
                $totalSkippedFiles++;
                $totalSkippedSize += $videoFile->currentSize;
                continue;
            }

            if (file_exists($optimalFilePath) && $recheckExistingOptimal) {
                $output->writeln(sprintf(
                    'Optimal version already exists (%s), re-checking and keeping the smaller result.',
                    $this->cliHelper->link($optimalFilePath),
                ));
            }

            $fileList[] = $videoFile;

            $sizeEstimate = $videoFile->sizeEstimate($videoFile->baseBitrate());
            $output->writeln(sprintf(
                "Dimensions: %sx%s\nCurrent bitrate: %s Kbps\nPixel format: %s\nCurrent size: %s\nProjected size: %s\nProjected Savings: %s",
                $videoFile->width,
                $videoFile->height,
                number_format((int) ($videoFile->bitRate / 1024), thousands_separator: ' '),
                $videoFile->pixFmt,
                $this->cliHelper->formatBytes($videoFile->currentSize),
                $this->cliHelper->formatBytes($sizeEstimate),
                $this->cliHelper->formatBytes($videoFile->currentSize - $sizeEstimate),
            ));

            if (! $videoFile->hasRotation) {
                continue;
            }

            $output->writeln('<info>Using CPU encoder due to video rotation</info>');
        }

        return [$fileList, $totalSkippedFiles, $totalSkippedSize];
    }

    private function startingBitrateForFile(VideoFile $file, bool $recheckExistingOptimal): int
    {
        if (! $recheckExistingOptimal) {
            return 0;
        }

        $optimalFilePath = $file->suffixedFilePath(VideoFile::OPTIMAL_SUFFIX);
        if (! file_exists($optimalFilePath)) {
            return 0;
        }

        try {
            $optimalVideoFile = $this->encoder->videoFileFromPath($optimalFilePath);

            return max(1, (int) ($optimalVideoFile->bitRate / 1024));
        } catch (Throwable) {
            return 0;
        }
    }
}
