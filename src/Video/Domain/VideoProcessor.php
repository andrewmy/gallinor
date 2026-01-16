<?php

declare(strict_types=1);

namespace App\Video\Domain;

use Psr\Log\LoggerInterface;
use RuntimeException;

use function copy;
use function filesize;
use function implode;
use function microtime;
use function rename;
use function sprintf;
use function sys_get_temp_dir;
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
        private ProcessExecutor $processExecutor,
    ) {
    }

    /** @param callable(int, float, int): void|null $statusCallback Called with (bitrate, vmafScore, saved) after each attempt */

    /** @param callable(string): void|null $lineCallback Called with each line of ffmpeg output during encoding */
    public function processVideo(
        VideoFile $file,
        bool $dryRun = false,
        callable|null $statusCallback = null,
        callable|null $lineCallback = null,
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
            [$tempFilePath, $processedSize] = $this->encode($file, $baseBitrate, $lineCallback);

            // Check if encoded file is larger than original - skip if so
            if ($processedSize >= $file->currentSize) {
                @unlink($tempFilePath);
                $this->logger->warning('Encoded file is larger than original, skipping', [
                    'file' => $file->path,
                    'original_size' => $file->currentSize,
                    'encoded_size' => $processedSize,
                ]);

                return new VideoProcessResult(
                    success: true,
                    skipped: true,
                    vmafScore: null,
                    originalSize: $file->currentSize,
                    newSize: 0,
                    qcTime: 0.0,
                    finalBitrate: $baseBitrate,
                    retryCount: 0,
                    outputPath: $file->path,
                );
            }

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
    private function encode(VideoFile $file, int $baseBitrate, callable|null $lineCallback = null): array
    {
        // Encode to system temp directory - if process is interrupted,
        // the partial file won't pollute the source directory.
        $tempFilePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('gallinor_', true) . '.mp4';

        $ffmpegCmd = $this->encoder->commandForFile($file, $baseBitrate, self::MAX_BITRATE_SPIKE, $tempFilePath);

        $result = $this->processExecutor->execute($ffmpegCmd, $lineCallback);

        if (! $result->isSuccessful()) {
            @unlink($tempFilePath);

            throw new RuntimeException(sprintf(
                "ffmpeg command failed with exit code %s:\n%s",
                $result->exitCode,
                implode("\n", $result->output),
            ));
        }

        $processedSize = (int) filesize($tempFilePath);

        return [$tempFilePath, $processedSize];
    }
}
