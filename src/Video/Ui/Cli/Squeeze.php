<?php

declare(strict_types=1);

namespace App\Video\Ui\Cli;

use App\Shared\Domain\FilesystemScanner;
use App\Shared\Domain\Platform;
use App\Shared\Ui\Cli\CliHelper;
use App\Shared\Ui\Cli\Timing;
use App\Video\Domain\EncoderFactory;
use App\Video\Domain\Exceptions\UnsupportedResolution;
use App\Video\Domain\VideoFile;
use App\Video\Domain\VideoFinder;
use App\Video\Domain\VideoProcessor;
use App\Video\Infrastructure\RealProcessExecutor;
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
use function microtime;
use function number_format;
use function sprintf;
use function str_contains;

use const PHP_EOL;

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

    /** @param list<string> $directories */
    public function __invoke(
        OutputInterface $output,
        #[Option]
        bool $dryRun = false,
        #[Option(description: 'Force using the CPU encoder, slow')]
        bool $useCpu = false,
        #[Argument]
        array $directories = [],
    ): int {
        $output->writeln(sprintf('<info>Dry run: %s</info>%s', $dryRun ? 'Yes' : 'No', PHP_EOL));
        $output->writeln(sprintf('<info>Init time: %s</info>%s', $this->timing->formatInit(), PHP_EOL));

        $startTime = microtime(true);
        try {
            $encoder           = $this->encoderFactory->create($useCpu);
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

        $totalProcessedSize = 0;
        $totalErroredFiles  = 0;
        $totalQcTime        = 0;

        [$fileList, $totalSkippedFiles] = $this->gatherFileList(
            directories: $directories,
            output: $output,
        );

        $totalCurrentSize   = array_reduce(
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
            "\n\nProjection:\n  Current size: %s\n  Projected size: %s\n  Projected savings: %s\n  Skipped: %d",
            $this->cliHelper->formatBytes($totalCurrentSize),
            $this->cliHelper->formatBytes($totalProjectedSize),
            $this->cliHelper->formatBytes($totalCurrentSize - $totalProjectedSize),
            $totalSkippedFiles,
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

        $fileCount   = count($fileList);
        $progressBar = $this->cliHelper->createProgressBar($output, $fileCount, 'Videos');
        $progressBar->start();

        $totalSavings = 0;
        $cliHelper    = $this->cliHelper;

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
                $result = $this->processor->processVideo($file, false, $statusCallback, $lineCallback);

                if ($result->skipped) {
                    $progressBar->clear();
                    $output->write('<comment>Skipped (bitrate acceptable): </comment>');
                    $output->writeln($this->cliHelper->link($file->path));
                    $progressBar->display();
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

                $totalProcessedSize += $result->newSize;
                $totalQcTime        += $result->qcTime;

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
            processed: $fileCount,
            skipped: $totalSkippedFiles,
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
     * @return array{list<VideoFile>, int} Tuple of file list and total skipped files
     */
    private function gatherFileList(
        array $directories,
        OutputInterface $output,
    ): array {
        $fileList          = [];
        $totalSkippedFiles = 0;

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
                    continue;
                }
            } catch (UnsupportedResolution $exception) {
                $output->writeln($exception->getMessage());
                $totalSkippedFiles++;
                continue;
            }

            $optimalFilePath = $videoFile->suffixedFilePath(VideoFile::OPTIMAL_SUFFIX);
            if (file_exists($optimalFilePath)) {
                $output->writeln(sprintf(
                    'Optimal version already exists (%s), skipping.',
                    $this->cliHelper->link($optimalFilePath),
                ));
                $totalSkippedFiles++;
                continue;
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
        }

        return [$fileList, $totalSkippedFiles];
    }
}
