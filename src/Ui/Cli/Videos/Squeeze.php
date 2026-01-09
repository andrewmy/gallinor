<?php

declare(strict_types=1);

namespace App\Ui\Cli\Videos;

use App\Domain\Exceptions\UnsupportedResolution;
use App\Domain\Ffmpeg;
use App\Domain\Platform;
use App\Domain\VideoEncoder;
use App\Domain\VideoFile;
use App\Ui\Cli\CliHelper;
use FilesystemIterator;
use Psr\Log\LoggerInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

use function array_reduce;
use function assert;
use function basename;
use function ceil;
use function count;
use function exec;
use function file_exists;
use function filesize;
use function microtime;
use function number_format;
use function rename;
use function rtrim;
use function sprintf;
use function str_ends_with;
use function trim;
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
    ) {
        parent::__construct();
    }

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
            $this->platform = new Platform();
            $this->ffmpeg   = new Ffmpeg($useCpu, $this->platform);
        } catch (Throwable $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');

            return self::FAILURE;
        }

        $output->writeln(sprintf('<info>Available cores: %d</info>', $this->platform->nCores));
        $output->writeln(sprintf('<info>Using encoder: %s</info>', $this->ffmpeg->activeEncoder->value));
        if ($this->ffmpeg->activeEncoder === VideoEncoder::Nvidia) {
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
            static fn (int $carry, VideoFile $file) => $carry + $file->currentSizeKb,
            0,
        );
        $totalProjectedSize = array_reduce(
            $fileList,
            static fn (int $carry, VideoFile $file) => $carry + $file->sizeEstimate($file->baseBitrate()),
            0,
        );

        $output->writeln(sprintf(
            "\n\nProjection:\n  Current size: %s KB\n  Projected size: %s KB\n  Projected savings: %s KB\n  Skipped: %d",
            number_format($totalCurrentSize, thousands_separator: ' '),
            number_format($totalProjectedSize, thousands_separator: ' '),
            number_format($totalCurrentSize - $totalProjectedSize, thousands_separator: ' '),
            $totalSkippedFiles,
        ));
        $gatherTime = microtime(true);
        $output->writeln(sprintf('<info>Gather time: %.3fs</info>', $gatherTime - $initTime));

        if ($dryRun) {
            return self::SUCCESS;
        }

        $fileCount   = count($fileList);
        $progressBar = $this->cliHelper->createProgressBar($output, $fileCount, 'Videos');
        $progressBar->start();

        $totalSavings = 0;

        foreach ($fileList as $file) {
            $fileName = basename($file->path);
            $progressBar->setMessage($fileName, 'status');
            $progressBar->display();

            $statusCallback = static function (int $bitrate, float $vmafScore, int $savedKb) use ($progressBar, $fileName, &$totalSavings): void {
                $runningTotal = $totalSavings + $savedKb;
                $progressBar->setMessage(
                    sprintf('%s | %sk, VMAF=%.1f, saved %s KB (total: %s KB)', $fileName, $bitrate, $vmafScore, number_format($savedKb, thousands_separator: ' '), number_format($runningTotal, thousands_separator: ' ')),
                    'status',
                );
                $progressBar->display();
            };

            try {
                [$processedSize, $qcTime, $vmafScore] = $this->processFile($file, $output, $statusCallback);
                $totalProcessedSize                  += $processedSize;
                $totalQcTime                         += $qcTime;

                $savings       = $file->currentSizeKb - $processedSize;
                $totalSavings += $savings;
                $progressBar->setMessage(
                    sprintf('%s | VMAF=%.1f, saved %s KB (total: %s KB)', $fileName, $vmafScore, number_format($savings, thousands_separator: ' '), number_format($totalSavings, thousands_separator: ' ')),
                    'status',
                );
            } catch (Throwable $exception) {
                $progressBar->setMessage(sprintf('%s | <error>Error</error>', $fileName), 'status');
                if ($output->isVerbose()) {
                    $progressBar->clear();
                    $output->writeln(sprintf('<error>%s: %s</error>', $fileName, $exception->getMessage()));
                    $progressBar->display();
                }

                $totalErroredFiles++;
            }

            $progressBar->advance();
        }

        $progressBar->setMessage('Done', 'status');
        $progressBar->finish();
        $output->writeln('');

        $processTime = microtime(true);
        $output->writeln(sprintf(
            "\nVideo Summary:\n  Processed: %d\n  Skipped: %d\n  Errored: %d\n  Size before: %s KB\n  Size after: %s KB\n  Savings: %s KB",
            $fileCount,
            $totalSkippedFiles,
            $totalErroredFiles,
            number_format($totalCurrentSize, thousands_separator: ' '),
            number_format($totalProcessedSize, thousands_separator: ' '),
            number_format($totalCurrentSize - $totalProcessedSize, thousands_separator: ' '),
        ));
        $output->writeln(sprintf(
            "\n<info>Timing:\n  Init: %.3fs\n  Gather: %.3fs\n  Process: %.3fs\n  QC: %.3fs\n  Total: %.3fs</info>",
            $initTime - $startTime,
            $gatherTime - $initTime,
            $processTime - $gatherTime,
            $totalQcTime,
            $processTime - $startTime,
        ));

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

        foreach ($directories as $directory) {
            $directory = rtrim(trim($directory, '"\' '), DIRECTORY_SEPARATOR);
            $output->writeln(sprintf('Directory: %s', $this->cliHelper->link($directory)));
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(directory: $directory, flags: FilesystemIterator::SKIP_DOTS),
            );
            foreach ($files as $file) {
                assert($file instanceof SplFileInfo);
                if (! $file->isFile() || $file->getExtension() !== 'mp4') {
                    continue;
                }

                $filePath = $file->getPathname();
                if (
                    str_ends_with($filePath, '.' . VideoFile::OPTIMAL_SUFFIX . '.mp4')
                    || str_ends_with($filePath, '.tmp.mp4')
                ) {
                    $output->writeln(sprintf('Skipping auxiliary file: %s', $this->cliHelper->link($filePath)));
                    $totalSkippedFiles++;
                    continue;
                }

                $output->writeln(sprintf("\nFile: %s", $this->cliHelper->link($filePath)));

                try {
                    $videoFile = $this->ffmpeg->videoFileFromPath($filePath);
                } catch (Throwable $exception) {
                    $output->writeln(sprintf('<error>%s</error>', $exception->getMessage()));
                    $totalSkippedFiles++;
                    continue;
                }

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
                    "Dimensions: %sx%s\nCurrent bitrate: %s Kbps\nPixel format: %s\nCurrent size: %s KB\nProjected size: %s KB\nProjected Savings: %s KB",
                    $videoFile->width,
                    $videoFile->height,
                    number_format((int) ($videoFile->bitRate / 1024), thousands_separator: ' '),
                    $videoFile->pixFmt,
                    number_format($videoFile->currentSizeKb, thousands_separator: ' '),
                    number_format($sizeEstimate, thousands_separator: ' '),
                    number_format($videoFile->currentSizeKb - $sizeEstimate, thousands_separator: ' '),
                ));
            }
        }

        return [$fileList, $totalSkippedFiles];
    }

    private function isBitrateAcceptable(VideoFile $file, int $baseBitrate): bool
    {
        return (int) ($file->bitRate / 1024) <= $baseBitrate * $this->maxBitrateOverhead;
    }

    /**
     * @param callable(int, float, int): void $statusCallback Called with (bitrate, vmafScore, savedKb)
     *
     * @return array{int, float, float} [processedSizeKb, qcTime, vmafScore]
     */
    private function processFile(
        VideoFile $file,
        OutputInterface $output,
        callable $statusCallback,
    ): array {
        $baseBitrate = $file->baseBitrate();
        $qcTime      = 0.0;
        $vmafScore   = 0.0;

        if ($this->isBitrateAcceptable($file, $baseBitrate)) {
            if ($output->isVerbose()) {
                $output->writeln(sprintf(
                    '<info>File bitrate %s Kbps is acceptable with base bitrate %s Kbps, skipping.</info>',
                    (int) ($file->bitRate / 1024),
                    $baseBitrate,
                ));
            }

            return [$file->currentSizeKb, 0.0, 100.0];
        }

        $retryCount = 0;
        do {
            [$tempFilePath, $processedSizeKb] = $this->encode($file, $output, $baseBitrate);

            $startTime = microtime(true);
            $vmafScore = $this->ffmpeg->vmafScore(
                originalFilePath: $file->path,
                processedFilePath: $tempFilePath,
            );
            $qcTime   += microtime(true) - $startTime;

            $statusCallback($baseBitrate, $vmafScore, $file->currentSizeKb - $processedSizeKb);

            if ($output->isVerbose()) {
                $output->writeln(sprintf('VMAF score: %.2f (bitrate: %sk)', $vmafScore, $baseBitrate));
            }

            $resultAccepted = true;
            if ($vmafScore >= $this->minVmafScore) {
                continue;
            }

            unlink($tempFilePath);
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
        rename($tempFilePath, $newFilePath);

        if ($output->isVerbose()) {
            $output->writeln(sprintf('<info>Saved: %s</info>', $this->cliHelper->link($newFilePath)));
        }

        $this->logger->info('Processed file', [
            'original_file' => $file->path,
            'original_size_kb' => $file->currentSizeKb,
            'processed_file' => $newFilePath,
            'processed_size_kb' => $processedSizeKb,
            'base_bitrate_kbps' => $baseBitrate,
            'vmaf_score' => $vmafScore,
            'retry_count' => $retryCount,
        ]);

        return [$processedSizeKb, $qcTime, $vmafScore];
    }

    /** @return array{string, int} */
    private function encode(
        VideoFile $file,
        OutputInterface $output,
        int $baseBitrate,
    ): array {
        $tempFilePath = $file->suffixedFilePath('tmp');

        $ffmpegCmd = $this->ffmpeg->commandForFile($file, $baseBitrate, $this->maxBitrateSpikes, $tempFilePath);
        if ($output->isVerbose()) {
            $output->writeln(sprintf('Executing command: %s', $ffmpegCmd));
        }

        exec($ffmpegCmd, $ffmpegOutput, $ffmpegExitCode);
        if ($output->isVerbose()) {
            foreach ($ffmpegOutput as $line) {
                $output->writeln(sprintf('<comment>%s</comment>', $line));
            }
        }

        if ($ffmpegExitCode !== 0) {
            unlink($tempFilePath);

            throw new RuntimeException(
                sprintf('ffmpeg command failed with exit code %s, skipping file.', $ffmpegExitCode),
            );
        }

        $processedSizeKb = (int) ceil(filesize($tempFilePath) / 1024);
        if ($output->isVerbose()) {
            $output->writeln(sprintf(
                'Encoded: %s KB (saved %s KB)',
                number_format($processedSizeKb, thousands_separator: ' '),
                number_format($file->currentSizeKb - $processedSizeKb, thousands_separator: ' '),
            ));
        }

        return [$tempFilePath, $processedSizeKb];
    }
}
