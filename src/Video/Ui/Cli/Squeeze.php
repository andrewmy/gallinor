<?php

declare(strict_types=1);

namespace App\Video\Ui\Cli;

use App\Shared\Domain\FilesystemScanner;
use App\Shared\Domain\Platform;
use App\Shared\Ui\Cli\CliHelper;
use App\Shared\Ui\Cli\Timing;
use App\Video\Domain\Encoder;
use App\Video\Domain\Exceptions\UnsupportedResolution;
use App\Video\Domain\Ffmpeg;
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
use function file_exists;
use function microtime;
use function number_format;
use function sprintf;

use const PHP_EOL;

#[AsCommand(name: 'videos:squeeze', description: 'Re-encode videos to optimal bitrate')]
final class Squeeze extends Command
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly CliHelper $cliHelper,
        private readonly FilesystemScanner $scanner,
    ) {
        parent::__construct();
    }

    private VideoFinder $videoFinder;

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

        $startTime = microtime(true);
        try {
            $platform          = new Platform();
            $ffmpeg            = new Ffmpeg($useCpu, $platform);
            $this->videoFinder = new VideoFinder($ffmpeg);
            $processor         = new VideoProcessor($ffmpeg, $this->logger);
        } catch (Throwable $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');

            return self::FAILURE;
        }

        $output->writeln(sprintf('<info>Available cores: %d</info>', $platform->nCores));
        $output->writeln(sprintf('<info>Using encoder: %s</info>', $ffmpeg->activeEncoder->value));
        if ($ffmpeg->activeEncoder === Encoder::Nvidia) {
            $output->writeln(sprintf(
                '<info>NVENC Temporal AQ: %s</info>',
                $ffmpeg->hasTemporalAq ? 'available' : 'not available',
            ));
        }

        if (! $ffmpeg->hasVmaf) {
            $output->writeln('<error>VMAF is not available. Quality checking is required. Aborting.</error>');

            return self::FAILURE;
        }

        $initTime = microtime(true);
        $output->writeln(sprintf('<info>Init time: %.3fs</info>', $initTime - $startTime));

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
        $output->writeln(sprintf('<info>Gather time: %.3fs</info>', $gatherTime - $initTime));

        if ($dryRun) {
            $output->writeln('');
            new Timing(
                total: $gatherTime - $startTime,
                init: $initTime - $startTime,
                gather: $gatherTime - $initTime,
            )->print($output);

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

            $statusCallback = static function (int $bitrate, float $vmafScore, int $saved) use ($progressBar, $fileName, &$totalSavings, $cliHelper): void {
                $runningTotal = $totalSavings + $saved;
                $progressBar->setMessage(
                    sprintf('%s | %sk, VMAF=%.1f, saved %s (total: %s)', $fileName, $bitrate, $vmafScore, $cliHelper->formatBytes($saved), $cliHelper->formatBytes($runningTotal)),
                    'status',
                );
                $progressBar->display();
            };

            try {
                $result = $processor->processVideo($file, false, $statusCallback);

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
                $progressBar->setMessage(
                    sprintf('%s | VMAF=%.1f, saved %s (total: %s)', $fileName, $result->vmafScore, $cliHelper->formatBytes($savings), $cliHelper->formatBytes($totalSavings)),
                    'status',
                );
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
        new Timing(
            total: $processTime - $startTime,
            init: $initTime - $startTime,
            gather: $gatherTime - $initTime,
            process: $processTime - $gatherTime,
            qc: $totalQcTime,
        )->print($output);

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
