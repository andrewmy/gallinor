<?php

declare(strict_types=1);

namespace App\Video\Domain;

use Psr\Log\LoggerInterface;
use RuntimeException;

use function copy;
use function fclose;
use function feof;
use function fgets;
use function filesize;
use function implode;
use function is_resource;
use function microtime;
use function proc_close;
use function proc_open;
use function rename;
use function sprintf;
use function sys_get_temp_dir;
use function trim;
use function uniqid;
use function unlink;

use const DIRECTORY_SEPARATOR;

final readonly class VideoProcessor
{
    private const float MIN_VMAF_SCORE       = 90.0;
    private const float MAX_BITRATE_SPIKE    = 1.25;
    private const float MAX_BITRATE_OVERHEAD = 1.1;

    public function __construct(
        private Encoder $encoder,
        private LoggerInterface $logger,
    ) {
    }

    /** @param callable(int, float, int): void|null $statusCallback Called with (bitrate, vmafScore, saved) after each attempt */
    public function processVideo(
        VideoFile $file,
        bool $dryRun = false,
        callable|null $statusCallback = null,
    ): VideoProcessResult {
        $baseBitrate = $file->baseBitrate();

        if (self::isBitrateAcceptable($file, $baseBitrate)) {
            return new VideoProcessResult(
                success: true,
                skipped: true,
                vmafScore: 100.0,
                originalSize: $file->currentSize,
                newSize: $file->currentSize,
                qcTime: 0.0,
                finalBitrate: $baseBitrate,
                retryCount: 0,
                outputPath: $file->path,
            );
        }

        if ($dryRun) {
            $projectedSize = $file->sizeEstimate($baseBitrate);

            return new VideoProcessResult(
                success: true,
                skipped: false,
                vmafScore: null,
                originalSize: $file->currentSize,
                newSize: $projectedSize,
                qcTime: 0.0,
                finalBitrate: $baseBitrate,
                retryCount: 0,
                outputPath: $file->suffixedFilePath(VideoFile::OPTIMAL_SUFFIX),
            );
        }

        $retryCount = 0;
        $qcTime     = 0.0;

        do {
            [$tempFilePath, $processedSize] = $this->encode($file, $baseBitrate);

            $startTime = microtime(true);
            $vmafScore = $this->encoder->qualityScore(
                originalFilePath: $file->path,
                processedFilePath: $tempFilePath,
            );
            $qcTime   += microtime(true) - $startTime;

            if ($statusCallback !== null) {
                $statusCallback($baseBitrate, $vmafScore, $file->currentSize - $processedSize);
            }

            $resultAccepted = $vmafScore >= self::MIN_VMAF_SCORE;

            if ($resultAccepted) {
                continue;
            }

            @unlink($tempFilePath);
            $retryCount++;

            $bitrateStep = $file->bitrateStep();
            if ($bitrateStep === null) {
                // No bitrate step defined, cannot retry
                $this->logger->warning('Cannot retry encoding: no bitrate step defined for resolution', [
                    'file' => $file->path,
                    'width' => $file->width,
                    'height' => $file->height,
                ]);

                return new VideoProcessResult(
                    success: false,
                    skipped: false,
                    vmafScore: $vmafScore,
                    originalSize: $file->currentSize,
                    newSize: 0,
                    qcTime: $qcTime,
                    finalBitrate: $baseBitrate,
                    retryCount: $retryCount,
                    outputPath: '',
                );
            }

            $baseBitrate += $bitrateStep;
        } while (! $resultAccepted);

        $newFilePath = $file->suffixedFilePath(VideoFile::OPTIMAL_SUFFIX);
        // rename() fails across filesystems (temp vs target), fall back to copy+delete
        if (! @rename($tempFilePath, $newFilePath)) {
            copy($tempFilePath, $newFilePath);
            @unlink($tempFilePath);
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

        return new VideoProcessResult(
            success: true,
            skipped: false,
            vmafScore: $vmafScore,
            originalSize: $file->currentSize,
            newSize: $processedSize,
            qcTime: $qcTime,
            finalBitrate: $baseBitrate,
            retryCount: $retryCount,
            outputPath: $newFilePath,
        );
    }

    public static function isBitrateAcceptable(VideoFile $file, int $baseBitrate): bool
    {
        return (int) ($file->bitRate / 1024) <= $baseBitrate * self::MAX_BITRATE_OVERHEAD;
    }

    /** @return array{string, int} [tempFilePath, processedSize] */
    private function encode(VideoFile $file, int $baseBitrate): array
    {
        // Encode to system temp directory - if process is interrupted,
        // the partial file won't pollute the source directory.
        $tempFilePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('gallinor_', true) . '.mp4';

        $ffmpegCmd = $this->encoder->commandForFile($file, $baseBitrate, self::MAX_BITRATE_SPIKE, $tempFilePath);

        $ffmpegOutput   = [];
        $ffmpegExitCode = 0;

        // Use proc_open for better control over the process
        $descriptorspec = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = @proc_open($ffmpegCmd, $descriptorspec, $pipes);

        if (! is_resource($proc)) {
            throw new RuntimeException('Failed to start ffmpeg process');
        }

        $stdout = $pipes[1];
        $stderr = $pipes[2];

        while (! feof($stdout)) {
            $line = fgets($stdout);
            if ($line === false || $line === '') {
                break;
            }

            $ffmpegOutput[] = trim($line);
        }

        while (! feof($stderr)) {
            $line = fgets($stderr);
            if ($line === false || $line === '') {
                break;
            }

            $ffmpegOutput[] = trim($line);
        }

        fclose($stdout);
        fclose($stderr);

        $ffmpegExitCode = proc_close($proc);

        if ($ffmpegExitCode !== 0) {
            @unlink($tempFilePath);

            throw new RuntimeException(sprintf(
                "ffmpeg command failed with exit code %s:\n%s",
                $ffmpegExitCode,
                implode("\n", $ffmpegOutput),
            ));
        }

        $processedSize = (int) filesize($tempFilePath);

        return [$tempFilePath, $processedSize];
    }
}
