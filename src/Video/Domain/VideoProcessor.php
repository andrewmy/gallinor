<?php

declare(strict_types=1);

namespace App\Video\Domain;

use App\Shared\Domain\ProcessExecutor;
use Psr\Log\LoggerInterface;
use RuntimeException;

use function copy;
use function filesize;
use function implode;
use function intdiv;
use function max;
use function microtime;
use function min;
use function rename;
use function sprintf;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const DIRECTORY_SEPARATOR;

final readonly class VideoProcessor
{
    private const float MIN_VMAF_SCORE                         = 90.0;
    private const float ACCEPTABLE_VMAF_HEADROOM               = 1.0;
    private const float DOWNWARD_SEARCH_MIN_VMAF_SCORE         = 96.0;
    private const float MAX_BITRATE_SPIKE                      = 1.25;
    private const float MAX_BITRATE_OVERHEAD                   = 1.1;
    private const int VMAF_SUBSAMPLE                           = 10;
    private const int BRACKET_REFINEMENT_MAX_OVERSHOOT_PERCENT = 110;
    private const int RETRY_REFINEMENT_STEP_DIVISOR            = 4;
    private const int RETRY_REFINEMENT_MIN_STEP_KBPS           = 250;
    private const int RETRY_REFINEMENT_MAX_PROBES              = 4;

    public function __construct(
        private Encoder $encoder,
        private LoggerInterface $logger,
        private ProcessExecutor $processExecutor,
    ) {
    }

    /** @param callable(int, float, int, float, float): void|null $statusCallback Called with (bitrate, vmafScore, saved, encodeSeconds, scoreSeconds) after each scoring attempt */

    /** @param callable(string): void|null $lineCallback Called with each line of ffmpeg output during encoding */

    /** @param callable(int): void|null $attemptStartCallback Called with target bitrate (kbps) before each encode attempt */

    /** @param callable(int, int, float): void|null $scoringStartCallback Called with (bitrate, encodedSize, encodeSeconds) right before scoring starts */
    public function processVideo(
        VideoFile $file,
        bool $dryRun = false,
        callable|null $statusCallback = null,
        callable|null $lineCallback = null,
        callable|null $attemptStartCallback = null,
        callable|null $scoringStartCallback = null,
    ): VideoProcessResult {
        $defaultBaseBitrate = $file->baseBitrate();
        $baseBitrate        = $defaultBaseBitrate;

        if (self::isBitrateAcceptable($file, $defaultBaseBitrate)) {
            return new VideoProcessResult(
                success: true,
                skipped: true,
                vmafScore: 100.0,
                originalSize: $file->currentSize,
                newSize: $file->currentSize,
                qcTime: 0.0,
                finalBitrate: $defaultBaseBitrate,
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

        $bitrateStep = $file->bitrateStep();

        while (true) {
            $candidate     = $this->runProbe(
                file: $file,
                bitrateKbps: $baseBitrate,
                qcTime: $qcTime,
                lineCallback: $lineCallback,
                statusCallback: $statusCallback,
                attemptStartCallback: $attemptStartCallback,
                scoringStartCallback: $scoringStartCallback,
            );
            $tempFilePath  = $candidate['tempFilePath'];
            $processedSize = $candidate['processedSize'];

            // Check if encoded file is larger than original - skip if so
            if ($candidate['isLargerThanOriginal']) {
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

            $vmafScore = $candidate['vmafScore'];

            if ($vmafScore >= self::MIN_VMAF_SCORE) {
                break;
            }

            @unlink($tempFilePath);
            $retryCount++;

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

            $adaptiveStep = $this->calculateAdaptiveBitrateStep($bitrateStep, $vmafScore);
            $this->logger->info('VMAF score below threshold, adjusting bitrate', [
                'file' => $file->path,
                'current_bitrate' => $baseBitrate,
                'vmaf_score' => $vmafScore,
                'base_step' => $bitrateStep,
                'adaptive_step' => $adaptiveStep,
                'retry_count' => $retryCount,
            ]);
            $baseBitrate += $adaptiveStep;
        }

        $bestTempFilePath  = $tempFilePath;
        $bestProcessedSize = $processedSize;
        $bestVmafScore     = $vmafScore;
        $bestBitrate       = $baseBitrate;

        // Probe lower bitrates and keep the smallest passing file.
        if (
            $bitrateStep !== null
            && ($bestVmafScore >= self::DOWNWARD_SEARCH_MIN_VMAF_SCORE || $retryCount > 0)
            && ! self::isWithinAcceptableVmafHeadroom($bestVmafScore)
        ) {
            $searchBitrate        = $bestBitrate;
            $probeCount           = 0;
            $lowestFailingBitrate = null;

            $downwardStep  = $retryCount > 0
                ? max(self::RETRY_REFINEMENT_MIN_STEP_KBPS, intdiv($bitrateStep, self::RETRY_REFINEMENT_STEP_DIVISOR))
                : $bitrateStep;
            $maxProbeCount = $retryCount > 0 ? self::RETRY_REFINEMENT_MAX_PROBES : null;

            while (true) {
                if ($maxProbeCount !== null && $probeCount >= $maxProbeCount) {
                    break;
                }

                $candidateBitrate = $searchBitrate - $downwardStep;
                if ($candidateBitrate < $downwardStep) {
                    break;
                }

                $candidateResult        = $this->runProbe(
                    file: $file,
                    bitrateKbps: $candidateBitrate,
                    qcTime: $qcTime,
                    lineCallback: $lineCallback,
                    statusCallback: $statusCallback,
                    attemptStartCallback: $attemptStartCallback,
                    scoringStartCallback: $scoringStartCallback,
                );
                $candidateTempFilePath  = $candidateResult['tempFilePath'];
                $candidateProcessedSize = $candidateResult['processedSize'];

                if ($candidateResult['isLargerThanOriginal']) {
                    @unlink($candidateTempFilePath);
                    break;
                }

                $candidateVmafScore = $candidateResult['vmafScore'];

                if ($candidateVmafScore < self::MIN_VMAF_SCORE) {
                    @unlink($candidateTempFilePath);
                    $lowestFailingBitrate = $candidateBitrate;
                    break;
                }

                $probeCount++;
                $searchBitrate = $candidateBitrate;
                $this->adoptBetterCandidateOrDiscard(
                    bestTempFilePath: $bestTempFilePath,
                    bestProcessedSize: $bestProcessedSize,
                    bestVmafScore: $bestVmafScore,
                    bestBitrate: $bestBitrate,
                    candidateTempFilePath: $candidateTempFilePath,
                    candidateProcessedSize: $candidateProcessedSize,
                    candidateVmafScore: $candidateVmafScore,
                    candidateBitrate: $candidateBitrate,
                );

                if (self::isWithinAcceptableVmafHeadroom($candidateVmafScore)) {
                    break;
                }
            }

            if ($lowestFailingBitrate !== null) {
                $passingBitrate = $searchBitrate;
                $failingBitrate = $lowestFailingBitrate;
                $refinementStep = max(
                    self::RETRY_REFINEMENT_MIN_STEP_KBPS,
                    intdiv($bitrateStep, self::RETRY_REFINEMENT_STEP_DIVISOR),
                );

                while ($passingBitrate * 100 > $failingBitrate * self::BRACKET_REFINEMENT_MAX_OVERSHOOT_PERCENT) {
                    $candidateBitrate = self::snapBitrateToStep(
                        intdiv($passingBitrate + $failingBitrate, 2),
                        $refinementStep,
                    );

                    if ($candidateBitrate <= $failingBitrate || $candidateBitrate >= $passingBitrate) {
                        break;
                    }

                    $candidateResult        = $this->runProbe(
                        file: $file,
                        bitrateKbps: $candidateBitrate,
                        qcTime: $qcTime,
                        lineCallback: $lineCallback,
                        statusCallback: $statusCallback,
                        attemptStartCallback: $attemptStartCallback,
                        scoringStartCallback: $scoringStartCallback,
                    );
                    $candidateTempFilePath  = $candidateResult['tempFilePath'];
                    $candidateProcessedSize = $candidateResult['processedSize'];

                    if ($candidateResult['isLargerThanOriginal']) {
                        @unlink($candidateTempFilePath);
                        $failingBitrate = $candidateBitrate;
                        continue;
                    }

                    $candidateVmafScore = $candidateResult['vmafScore'];
                    if ($candidateVmafScore < self::MIN_VMAF_SCORE) {
                        @unlink($candidateTempFilePath);
                        $failingBitrate = $candidateBitrate;
                        continue;
                    }

                    $passingBitrate = $candidateBitrate;
                    $this->adoptBetterCandidateOrDiscard(
                        bestTempFilePath: $bestTempFilePath,
                        bestProcessedSize: $bestProcessedSize,
                        bestVmafScore: $bestVmafScore,
                        bestBitrate: $bestBitrate,
                        candidateTempFilePath: $candidateTempFilePath,
                        candidateProcessedSize: $candidateProcessedSize,
                        candidateVmafScore: $candidateVmafScore,
                        candidateBitrate: $candidateBitrate,
                    );

                    if (self::isWithinAcceptableVmafHeadroom($candidateVmafScore)) {
                        break;
                    }
                }
            }
        }

        $newFilePath = $file->suffixedFilePath(VideoFile::OPTIMAL_SUFFIX);

        // rename() fails across filesystems (temp vs target), fall back to copy+delete
        if (! @rename($bestTempFilePath, $newFilePath)) {
            copy($bestTempFilePath, $newFilePath);
            @unlink($bestTempFilePath);
        }

        $this->logger->info('Processed file', [
            'original_file' => $file->path,
            'original_size' => $file->currentSize,
            'processed_file' => $newFilePath,
            'processed_size' => $bestProcessedSize,
            'base_bitrate_kbps' => $bestBitrate,
            'vmaf_score' => $bestVmafScore,
            'retry_count' => $retryCount,
        ]);

        return new VideoProcessResult(
            success: true,
            skipped: false,
            vmafScore: $bestVmafScore,
            originalSize: $file->currentSize,
            newSize: $bestProcessedSize,
            qcTime: $qcTime,
            finalBitrate: $bestBitrate,
            retryCount: $retryCount,
            outputPath: $newFilePath,
        );
    }

    public static function isBitrateAcceptable(VideoFile $file, int $baseBitrate): bool
    {
        return (int) ($file->bitRate / 1024) <= $baseBitrate * self::MAX_BITRATE_OVERHEAD;
    }

    private function calculateAdaptiveBitrateStep(int $baseStep, float $vmafScore): int
    {
        $distance   = self::MIN_VMAF_SCORE - $vmafScore;
        $multiplier = 1.8 ** ($distance / 15);
        $multiplier = max(1.0, min(4.0, $multiplier));

        return (int) ($baseStep * $multiplier);
    }

    private static function isWithinAcceptableVmafHeadroom(float $vmafScore): bool
    {
        return $vmafScore >= self::MIN_VMAF_SCORE
            && $vmafScore <= self::MIN_VMAF_SCORE + self::ACCEPTABLE_VMAF_HEADROOM;
    }

    private function adoptBetterCandidateOrDiscard(
        string &$bestTempFilePath,
        int &$bestProcessedSize,
        float &$bestVmafScore,
        int &$bestBitrate,
        string $candidateTempFilePath,
        int $candidateProcessedSize,
        float $candidateVmafScore,
        int $candidateBitrate,
    ): void {
        if ($candidateProcessedSize >= $bestProcessedSize) {
            @unlink($candidateTempFilePath);

            return;
        }

        @unlink($bestTempFilePath);
        $bestTempFilePath  = $candidateTempFilePath;
        $bestProcessedSize = $candidateProcessedSize;
        $bestVmafScore     = $candidateVmafScore;
        $bestBitrate       = $candidateBitrate;
    }

    private static function snapBitrateToStep(int $bitrateKbps, int $stepKbps): int
    {
        if ($stepKbps <= 1) {
            return $bitrateKbps;
        }

        return max(
            $stepKbps,
            intdiv($bitrateKbps + intdiv($stepKbps, 2), $stepKbps) * $stepKbps,
        );
    }

    /**
     * @param callable(int, float, int, float, float): void|null $statusCallback
     * @param callable(int): void|null                           $attemptStartCallback
     * @param callable(int, int, float): void|null               $scoringStartCallback
     *
     * @return array{
     *   tempFilePath: string,
     *   processedSize: int,
     *   vmafScore: float,
     *   isLargerThanOriginal: bool
     * }
     */
    private function runProbe(
        VideoFile $file,
        int $bitrateKbps,
        float &$qcTime,
        callable|null $lineCallback = null,
        callable|null $statusCallback = null,
        callable|null $attemptStartCallback = null,
        callable|null $scoringStartCallback = null,
    ): array {
        $candidate = $this->evaluateBitrateCandidate(
            file: $file,
            bitrateKbps: $bitrateKbps,
            lineCallback: $lineCallback,
            statusCallback: $statusCallback,
            attemptStartCallback: $attemptStartCallback,
            scoringStartCallback: $scoringStartCallback,
        );

        $qcTime += $candidate['qcTime'];

        return [
            'tempFilePath' => $candidate['tempFilePath'],
            'processedSize' => $candidate['processedSize'],
            'vmafScore' => $candidate['vmafScore'],
            'isLargerThanOriginal' => $candidate['isLargerThanOriginal'],
        ];
    }

    /**
     * @param callable(int, float, int, float, float): void|null $statusCallback
     * @param callable(int): void|null                           $attemptStartCallback
     * @param callable(int, int, float): void|null               $scoringStartCallback
     *
     * @return array{
     *   tempFilePath: string,
     *   processedSize: int,
     *   vmafScore: float,
     *   qcTime: float,
     *   isLargerThanOriginal: bool
     * }
     */
    private function evaluateBitrateCandidate(
        VideoFile $file,
        int $bitrateKbps,
        callable|null $lineCallback = null,
        callable|null $statusCallback = null,
        callable|null $attemptStartCallback = null,
        callable|null $scoringStartCallback = null,
    ): array {
        if ($attemptStartCallback !== null) {
            $attemptStartCallback($bitrateKbps);
        }

        $encodeStartTime                = microtime(true);
        [$tempFilePath, $processedSize] = $this->encode($file, $bitrateKbps, $lineCallback);
        $encodeTime                     = microtime(true) - $encodeStartTime;
        if ($processedSize >= $file->currentSize) {
            return [
                'tempFilePath' => $tempFilePath,
                'processedSize' => $processedSize,
                'vmafScore' => 0.0,
                'qcTime' => 0.0,
                'isLargerThanOriginal' => true,
            ];
        }

        if ($scoringStartCallback !== null) {
            $scoringStartCallback($bitrateKbps, $processedSize, $encodeTime);
        }

        $startTime = microtime(true);
        $vmafScore = $this->encoder->qualityScore(
            originalFilePath: $file->path,
            processedFilePath: $tempFilePath,
            subsample: self::VMAF_SUBSAMPLE,
        );
        $scoreTime = microtime(true) - $startTime;
        $qcTime    = $scoreTime;

        if ($statusCallback !== null) {
            $statusCallback($bitrateKbps, $vmafScore, $file->currentSize - $processedSize, $encodeTime, $scoreTime);
        }

        return [
            'tempFilePath' => $tempFilePath,
            'processedSize' => $processedSize,
            'vmafScore' => $vmafScore,
            'qcTime' => $qcTime,
            'isLargerThanOriginal' => false,
        ];
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
