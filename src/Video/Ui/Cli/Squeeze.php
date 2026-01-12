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
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

use function array_reduce;
use function basename;
use function copy;
use function count;
use function exec;
use function file_exists;
use function filesize;
use function implode;
use function microtime;
use function number_format;
use function rename;
use function sprintf;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const DIRECTORY_SEPARATOR;
use const PHP_EOL;

#[AsCommand(name: 'videos:squeeze', description: 'Re-encode videos to optimal bitrate')]
final class Squeeze extends Command
{
    private Platform $platform;
    private Ffmpeg $ffmpeg;
    private float $maxBitrateOverhead = 1.1;
    private float $maxBitrateSpikes   = 1.25;
    private float $minVmafScore       = 90.0;

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
            $this->platform    = new Platform();
            $this->ffmpeg      = new Ffmpeg($useCpu, $this->platform);
            $this->videoFinder = new VideoFinder($this->ffmpeg);
        } catch (Throwable $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');

            return self::FAILURE;
        }

        $output->writeln(sprintf('<info>Available cores: %d</info>', $this->platform->nCores));
        $output->writeln(sprintf('<info>Using encoder: %s</info>', $this->ffmpeg->activeEncoder->value));
        if ($this->ffmpeg->activeEncoder === Encoder::Nvidia) {
            $output->writeln(sprintf(
                '<info>NVENC Temporal AQ: %s</info>',
                $this->ffmpeg->hasTemporalAq ? 'available' : 'not available',
            ));
        }

        if (! $this->ffmpeg->hasVmaf) {
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
                [$processedSize, $qcTime, $vmafScore, $skipped] = $this->processFile($file, $output, $statusCallback);

                if ($skipped) {
                    $progressBar->clear();
                    $output->write('<comment>Skipped (bitrate acceptable): </comment>');
                    $output->writeln($this->cliHelper->link($file->path));
                    $progressBar->display();
                    $progressBar->advance();
                    continue;
                }

                $totalProcessedSize += $processedSize;
                $totalQcTime        += $qcTime;

                $savings       = $file->currentSize - $processedSize;
                $totalSavings += $savings;
                $progressBar->setMessage(
                    sprintf('%s | VMAF=%.1f, saved %s (total: %s)', $fileName, $vmafScore, $cliHelper->formatBytes($savings), $cliHelper->formatBytes($totalSavings)),
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

        $files  = $this->scanner->scanDirectories($directories);
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
                if ($this->isBitrateAcceptable($videoFile, $videoFile->baseBitrate())) {
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

    private function isBitrateAcceptable(VideoFile $file, int $baseBitrate): bool
    {
        return (int) ($file->bitRate / 1024) <= $baseBitrate * $this->maxBitrateOverhead;
    }

    /**
     * @param callable(int, float, int): void $statusCallback Called with (bitrate, vmafScore, saved)
     *
     * @return array{int, float, float, bool} [processedSize, qcTime, vmafScore, skipped]
     */
    private function processFile(
        VideoFile $file,
        OutputInterface $output,
        callable $statusCallback,
    ): array {
        $baseBitrate = $file->baseBitrate();
        $qcTime      = 0.0;

        if ($this->isBitrateAcceptable($file, $baseBitrate)) {
            return [$file->currentSize, 0.0, 100.0, true];
        }

        $retryCount = 0;
        do {
            [$tempFilePath, $processedSize] = $this->encode($file, $output, $baseBitrate);

            $startTime = microtime(true);
            $vmafScore = $this->ffmpeg->vmafScore(
                originalFilePath: $file->path,
                processedFilePath: $tempFilePath,
            );
            $qcTime   += microtime(true) - $startTime;

            $statusCallback($baseBitrate, $vmafScore, $file->currentSize - $processedSize);

            if ($output->isVerbose()) {
                $output->writeln(sprintf('VMAF score: %.2f (bitrate: %sk)', $vmafScore, $baseBitrate));
            }

            $resultAccepted = true;
            if ($vmafScore >= $this->minVmafScore) {
                continue;
            }

            @unlink($tempFilePath);
            $resultAccepted = false;
            $retryCount++;

            if ($output->isVerbose()) {
                $output->writeln(sprintf(
                    '<comment>VMAF %.2f < %.1f, retrying with bitrate %sk</comment>',
                    $vmafScore,
                    $this->minVmafScore,
                    $baseBitrate + $file->bitrateStep(),
                ));
            }

            $baseBitrate += $file->bitrateStep();
        } while (! $resultAccepted);

        $newFilePath = $file->suffixedFilePath(VideoFile::OPTIMAL_SUFFIX);
        // rename() fails across filesystems (temp vs target), fall back to copy+delete
        if (! @rename($tempFilePath, $newFilePath)) {
            copy($tempFilePath, $newFilePath);
            @unlink($tempFilePath);
        }

        if ($output->isVerbose()) {
            $output->writeln(sprintf('<info>Saved: %s</info>', $this->cliHelper->link($newFilePath)));
        }

        $this->logger->info('Processed file', [
            'original_file' => $file->path,
            'original_size' => $file->currentSize,
            'processed_file' => $newFilePath,
            'processed_size' => $processedSize,
            'base_bitrate_kbps' => $baseBitrate,
            'vmaf_score' => $vmafScore,
            'retry_count' => $retryCount,
        ]);

        return [$processedSize, $qcTime, $vmafScore, false];
    }

    /** @return array{string, int} [tempFilePath, processedSize] */
    private function encode(
        VideoFile $file,
        OutputInterface $output,
        int $baseBitrate,
    ): array {
        // Encode to system temp directory - if process is interrupted,
        // the partial file won't pollute the source directory.
        $tempFilePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('gallinor_') . '.mp4';

        $ffmpegCmd = $this->ffmpeg->commandForFile($file, $baseBitrate, $this->maxBitrateSpikes, $tempFilePath);
        if ($output->isVerbose()) {
            $output->writeln(sprintf('Executing command: %s', $ffmpegCmd));
        }

        $ffmpegOutput = [];
        exec($ffmpegCmd, $ffmpegOutput, $ffmpegExitCode);
        if ($output->isVerbose()) {
            foreach ($ffmpegOutput as $line) {
                $output->writeln(sprintf('<comment>%s</comment>', $line));
            }
        }

        if ($ffmpegExitCode !== 0) {
            @unlink($tempFilePath);

            throw new RuntimeException(sprintf(
                "ffmpeg command failed with exit code %s:\n%s",
                $ffmpegExitCode,
                implode("\n", $ffmpegOutput),
            ));
        }

        $processedSize = (int) filesize($tempFilePath);
        if ($output->isVerbose()) {
            $output->writeln(sprintf(
                'Encoded: %s (saved %s)',
                $this->cliHelper->formatBytes($processedSize),
                $this->cliHelper->formatBytes($file->currentSize - $processedSize),
            ));
        }

        return [$tempFilePath, $processedSize];
    }
}
