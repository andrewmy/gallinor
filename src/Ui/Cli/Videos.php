<?php

declare(strict_types=1);

namespace App\Ui\Cli;

use App\Domain\Ffmpeg;
use App\Domain\Platform;
use App\Domain\VideoEncoder;
use App\Domain\VideoFile;
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
use function ceil;
use function count;
use function exec;
use function file_exists;
use function filesize;
use function number_format;
use function rename;
use function sprintf;
use function str_ends_with;
use function trim;
use function unlink;

use const DIRECTORY_SEPARATOR;

#[AsCommand(name: 'videos')]
final class Videos extends Command
{
    private Platform $platform;
    private Ffmpeg $ffmpeg;
    private float $maxBitrateOverhead = 1.1;

    public function __construct(private LoggerInterface $logger)
    {
        parent::__construct();
    }

    /** @param list<string> $directories */
    public function __invoke(
        OutputInterface $output,
        #[Option]
        bool $dryRun = false,
        #[Option]
        bool $replaceExisting = false,
        #[Option]
        bool $checkQuality = false,
        #[Option]
        bool $useCpu = false,
        #[Option]
        bool $overwrite = false,
        #[Argument]
        array $directories = [],
    ): int {
        $output->writeln(sprintf('<info>Dry Run: %s</info>', $dryRun ? 'Yes' : 'No'));
        $output->writeln(sprintf('<info>Overwrite: %s</info>', $overwrite ? 'Yes' : 'No'));
        if (! $dryRun) {
            $output->writeln(sprintf('<info>Replace Existing: %s</info>', $replaceExisting ? 'Yes' : 'No'));
            $output->writeln(sprintf('<info>Check Quality: %s</info>', $checkQuality ? 'Yes' : 'No'));
            if ($replaceExisting && ! $checkQuality) {
                $output->writeln('<comment>Warning: Replacing existing files without quality check may lead to data loss.</comment>');
            }
        }

        $output->writeln('');

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

        $output->writeln(
            sprintf('<info>VMAF support: %s</info>', $this->ffmpeg->hasVmaf ? 'available' : 'not available'),
        );
        if ($checkQuality && ! $this->ffmpeg->hasVmaf) {
            $output->writeln('<error>Quality check requested but VMAF is not available. Aborting.</error>');

            return self::FAILURE;
        }

        $output->writeln('');

        $totalProcessedSize = 0;
        $totalErroredFiles  = 0;
        $maxBitrateSpikes   = 1.25;
        $minVmafScore       = 90.0;

        [$fileList, $totalSkippedFiles] = $this->gatherFileList(
            directories: $directories,
            output: $output,
            overwrite: $overwrite,
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
            "\n\nTotal Current Size: %s KB\nTotal Projected Size: %s KB\nTotal Projected Savings: %s KB\nSkipped Files: %d",
            number_format($totalCurrentSize, thousands_separator: ' '),
            number_format($totalProjectedSize, thousands_separator: ' '),
            number_format($totalCurrentSize - $totalProjectedSize, thousands_separator: ' '),
            $totalSkippedFiles,
        ));

        if ($dryRun) {
            return self::SUCCESS;
        }

        $fileCount = count($fileList);
        $i         = 1;
        foreach ($fileList as $file) {
            $output->writeln('');
            $output->writeln(sprintf('Processing: %s, %d of %d', $file->path, $i, $fileCount));

            try {
                $totalProcessedSize += $this->processFile(
                    $file,
                    $output,
                    $maxBitrateSpikes,
                    $minVmafScore,
                    $replaceExisting,
                    $checkQuality,
                );
            } catch (Throwable $exception) {
                $output->writeln(sprintf('<error>%s</error>', $exception->getMessage()));
                $totalErroredFiles++;
            }

            $i++;
        }

        $output->writeln(sprintf(
            "\nTotal Actual Size: %s KB\nTotal Actual Savings: %s KB\nErrored Files: %d",
            number_format($totalProcessedSize, thousands_separator: ' '),
            number_format($totalCurrentSize - $totalProcessedSize, thousands_separator: ' '),
            $totalErroredFiles,
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
        bool $overwrite,
    ): array {
        $fileList          = [];
        $totalSkippedFiles = 0;

        foreach ($directories as $directory) {
            $directory = trim($directory, '"\' ' . DIRECTORY_SEPARATOR);
            $output->writeln(sprintf('Directory: %s', $directory));
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(directory: $directory, flags: FilesystemIterator::SKIP_DOTS),
            );
            foreach ($files as $file) {
                assert($file instanceof SplFileInfo);
                if (! $file->isFile() || $file->getExtension() !== 'mp4') {
                    continue;
                }

                $filePath = $file->getPathname();
                if (str_ends_with($filePath, '.optimal.mp4') || str_ends_with($filePath, '.tmp.mp4')) {
                    $output->writeln(sprintf('Skipping auxiliary file: %s', $filePath));
                    $totalSkippedFiles++;
                    continue;
                }

                $output->writeln(sprintf("\nFile: %s", $filePath));

                try {
                    $videoFile = $this->ffmpeg->videoFileFromPath($filePath);
                } catch (Throwable $exception) {
                    $output->writeln(sprintf('<error>%s</error>', $exception->getMessage()));
                    $totalSkippedFiles++;
                    continue;
                }

                if ($videoFile->baseBitrate() === null) {
                    $output->writeln(sprintf('Unsupported resolution, skipping: %sx%s', $videoFile->width, $videoFile->height));
                    $totalSkippedFiles++;
                    continue;
                }

                if ($this->isBitrateAcceptable($videoFile, $videoFile->baseBitrate())) {
                    $output->writeln(sprintf('Bitrate %s Kbps is acceptable, no action needed.', $videoFile->bitRate));
                    $totalSkippedFiles++;
                    continue;
                }

                if (!$overwrite) {
                    $optimalFilePath = $videoFile->suffixedFilePath('optimal');
                    if (file_exists($optimalFilePath)) {
                        $output->writeln(sprintf('Optimal version already exists (%s), skipping.', $optimalFilePath));
                        $totalSkippedFiles++;
                        continue;
                    }
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

    private function processFile(
        VideoFile $file,
        OutputInterface $output,
        float $maxBitrateSpikes,
        float $minVmafScore,
        bool $replaceExisting,
        bool $checkQuality,
    ): int {
        $resultAccepted = true;
        $baseBitrate    = $file->baseBitrate();
        do {
            if ($this->isBitrateAcceptable($file, $baseBitrate)) {
                $output->writeln(sprintf(
                    '<info>File bitrate %s Kbps is now acceptable with base bitrate %s Kbps, stopping.</info>',
                    (int) ($file->bitRate / 1024),
                    $baseBitrate,
                ));

                return $file->currentSizeKb;
            }

            [$tempFilePath, $processedSizeKb] = $this->encode($file, $output, $baseBitrate, $maxBitrateSpikes);

            if (! $checkQuality) {
                continue;
            }

            $output->write('Checking VMAF score... ');
            $vmafScore = $this->ffmpeg->vmafScore(
                originalFilePath: $file->path,
                processedFilePath: $tempFilePath,
            );
            $output->writeln(sprintf('%.2f', $vmafScore));
            $resultAccepted = true;
            if ($vmafScore >= $minVmafScore) {
                continue;
            }

            unlink($tempFilePath);
            $resultAccepted = false;

            $output->writeln(
                sprintf(
                    '<error>With bitrate %sk the VMAF score %.2f is below acceptable threshold %s, retrying with higher bitrate %sk.</error>',
                    $baseBitrate,
                    $vmafScore,
                    $minVmafScore,
                    $baseBitrate + $file->bitrateStep(),
                ),
            );

            $baseBitrate += $file->bitrateStep();
        } while (! $resultAccepted);

        if ($replaceExisting) {
            rename($tempFilePath, $file->path);
            $output->writeln('<info>Replaced original file with optimal version.</info>');
        } else {
            $newFilePath = $file->suffixedFilePath('optimal');
            rename($tempFilePath, $newFilePath);
            $output->writeln(sprintf('<info>Saved optimal file as: %s</info>', $newFilePath));
        }

        $this->logger->info('Processed file', [
            'original_file' => $file->path,
            'original_size_kb' => $file->currentSizeKb,
            'processed_file' => $replaceExisting ? $file->path : $newFilePath,
            'processed_size_kb' => $processedSizeKb,
            'base_bitrate_kbps' => $baseBitrate,
            'vmaf_score' => $checkQuality ? $vmafScore : null,
        ]);

        return $processedSizeKb;
    }

    /** @return array{string, int} */
    private function encode(
        VideoFile $file,
        OutputInterface $output,
        int $baseBitrate,
        float $maxBitrateSpikes,
    ): array {
        $tempFilePath = $file->suffixedFilePath('tmp');

        $ffmpegCmd = $this->ffmpeg->commandForFile($file, $baseBitrate, $maxBitrateSpikes, $tempFilePath);
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
        $output->writeln(sprintf(
            "Processed file size: %s KB\nSavings: %s KB",
            number_format($processedSizeKb, thousands_separator: ' '),
            number_format($file->currentSizeKb - $processedSizeKb, thousands_separator: ' '),
        ));

        return [$tempFilePath, $processedSizeKb];
    }
}
