<?php

declare(strict_types=1);

namespace App\Images\Domain;

use RuntimeException;

use function array_slice;
use function copy;
use function file_exists;
use function filesize;
use function implode;
use function microtime;
use function rename;
use function sprintf;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const DIRECTORY_SEPARATOR;

final readonly class ImageOptimizer
{
    private const float JPEG_MIN_SSIM_SCORE    = 85.0;
    private const float SSIM_EARLY_STOP_WINDOW = 1.0;

    private const int HEIC_Q_STEP = 2;

    public function __construct(
        private Ssimulacra2 $ssimulacra2,
        private FfmpegImageNormalizer $normalizer,
        private ExifMetadata $exiftool,
        private StrictMetadataVerifier $metadataVerifier,
    ) {
    }

    /**
     * Find minimal-quality optimized image that meets the SSIMULACRA2 threshold
     * and is smaller than the original JPEG.
     *
     * @param callable(int, float, int): void                                     $statusCallback      Called with (qualityValue, score, savedBytes)
     * @param callable(string, int|null, float|null, int|null, string|null): void $statusEventCallback
     */
    public function optimizeJpeg(
        ImageFile $file,
        HeicCodec $codec,
        callable $statusCallback,
        callable $statusEventCallback,
    ): ImageProcessingResult|CalculationSkipReason {
        $runId  = uniqid('gallinor-', true);
        $tmpDir = sys_get_temp_dir();

        $refPng  = $tmpDir . DIRECTORY_SEPARATOR . $runId . '-ref.png';
        $candPng = $tmpDir . DIRECTORY_SEPARATOR . $runId . '-cand.png';

        $format       = $codec->format();
        $tmpOptimized = $tmpDir . DIRECTORY_SEPARATOR . $runId . '.' . $format->extension();
        $finalPath    = $file->optimizedPathFor($format);

        try {
            $this->emitStatusEvent($statusEventCallback, 'prepare');
            $this->normalizer->jpegToUprightPng($file->path, $refPng);

            // HEIC quality search: increase q until threshold met, then refine to minimum passing q.
            $found = $this->findMinimumHeicQuality(
                refPng: $refPng,
                candPng: $candPng,
                tmpOptimized: $tmpOptimized,
                minScore: self::JPEG_MIN_SSIM_SCORE,
                statusCallback: $statusCallback,
                statusEventCallback: $statusEventCallback,
                totalQcTime: 0.0,
                originalSize: $file->size,
                requireSmallerThanOriginal: true,
                heicCodec: $codec,
            );

            if ($found === null) {
                $this->emitStatusEvent($statusEventCallback, 'decision', decision: 'quality_not_achieved');

                return CalculationSkipReason::QualityNotAchieved;
            }

            if ($found['size'] >= $file->size) {
                $this->emitStatusEvent(
                    $statusEventCallback,
                    'decision',
                    $found['quality'],
                    $found['score'],
                    $file->size - $found['size'],
                    'too_big',
                );

                return CalculationSkipReason::ReplacementNotSmaller;
            }

            $this->emitStatusEvent(
                $statusEventCallback,
                'finalize',
                $found['quality'],
                $found['score'],
                $file->size - $found['size'],
            );
            $this->moveFileOrFail($tmpOptimized, $finalPath);
            $this->emitStatusEvent(
                $statusEventCallback,
                'metadata',
                $found['quality'],
                $found['score'],
                $file->size - $found['size'],
            );
            $this->copyAndVerifyMetadataStrict($file->path, $finalPath);

            return new ImageProcessingResult(
                format: $codec->format(),
                optimizedSize: $found['size'],
                originalSize: $file->size,
                qualityValue: $found['quality'],
                qualityLabel: $codec->qualityLabel(),
                qualityScore: $found['score'],
                qcTime: $found['qcTime'],
            );
        } finally {
            $this->cleanup($refPng, $candPng, $tmpOptimized);
        }
    }

    /**
     * @param callable(int, float, int): void                                     $statusCallback
     * @param callable(string, int|null, float|null, int|null, string|null): void $statusEventCallback
     * @param float                                                               $totalQcTime         Mutable accumulator passed by value and returned via array
     *
     * @return array{quality: int, score: float, size: int, qcTime: float}|null
     */
    private function findMinimumHeicQuality(
        string $refPng,
        string $candPng,
        string $tmpOptimized,
        float $minScore,
        callable $statusCallback,
        callable $statusEventCallback,
        float $totalQcTime,
        int $originalSize,
        bool $requireSmallerThanOriginal,
        HeicCodec $heicCodec,
    ): array|null {
        $lastFailQuality  = null;
        $firstPassQuality = null;
        $fallbackTooBig   = null;

        for ($q = 40; $q <= 100; $q += 10) {
            $probe       = $this->probeHeicQuality($refPng, $candPng, $tmpOptimized, $q, $originalSize, $statusCallback, $statusEventCallback, $totalQcTime, $heicCodec);
            $score       = $probe['score'];
            $size        = $probe['size'];
            $totalQcTime = $probe['qcTime'];
            $saved       = $originalSize - $size;

            if ($requireSmallerThanOriginal && $size >= $originalSize) {
                if ($score < $minScore) {
                    // Lower quality won't improve the score; higher quality won't improve the size.
                    $this->emitStatusEvent($statusEventCallback, 'decision', $q, $score, $saved, 'too_big');

                    return ['quality' => $q, 'score' => $score, 'size' => $size, 'qcTime' => $totalQcTime];
                }

                // We might still find a smaller passing quality below this bound.
                $this->emitStatusEvent($statusEventCallback, 'decision', $q, $score, $saved, 'too_big');
                $fallbackTooBig   = ['quality' => $q, 'score' => $score, 'size' => $size, 'qcTime' => $totalQcTime];
                $firstPassQuality = $q;
                break;
            }

            if ($score >= $minScore) {
                if ($score <= $minScore + self::SSIM_EARLY_STOP_WINDOW) {
                    $this->emitStatusEvent($statusEventCallback, 'decision', $q, $score, $saved, 'pass');

                    return ['quality' => $q, 'score' => $score, 'size' => $size, 'qcTime' => $totalQcTime];
                }

                $this->emitStatusEvent($statusEventCallback, 'decision', $q, $score, $saved, 'pass');
                $firstPassQuality = $q;
                break;
            }

            $this->emitStatusEvent($statusEventCallback, 'decision', $q, $score, $saved, 'score_low');
            $lastFailQuality = $q;
            $this->cleanup($tmpOptimized);
        }

        if ($firstPassQuality === null) {
            return null;
        }

        $start = $lastFailQuality === null ? 0 : $lastFailQuality + self::HEIC_Q_STEP;
        $best  = null;

        for ($q = $start; $q <= $firstPassQuality; $q += self::HEIC_Q_STEP) {
            $probe       = $this->probeHeicQuality($refPng, $candPng, $tmpOptimized, $q, $originalSize, $statusCallback, $statusEventCallback, $totalQcTime, $heicCodec);
            $score       = $probe['score'];
            $size        = $probe['size'];
            $totalQcTime = $probe['qcTime'];
            $saved       = $originalSize - $size;

            if ($requireSmallerThanOriginal && $size >= $originalSize) {
                // Higher quality will only increase size.
                $this->emitStatusEvent($statusEventCallback, 'decision', $q, $score, $saved, 'too_big');
                $this->cleanup($tmpOptimized);
                break;
            }

            if ($score < $minScore) {
                $this->emitStatusEvent($statusEventCallback, 'decision', $q, $score, $saved, 'score_low');
                $this->cleanup($tmpOptimized);
                continue;
            }

            $this->emitStatusEvent($statusEventCallback, 'decision', $q, $score, $saved, 'pass');
            $best = ['quality' => $q, 'score' => $score, 'size' => $size, 'qcTime' => $totalQcTime];
            break;
        }

        return $best ?? $fallbackTooBig;
    }

    /**
     * @param callable(int, float, int): void                                     $statusCallback      Called with (q, score, savedBytes)
     * @param callable(string, int|null, float|null, int|null, string|null): void $statusEventCallback
     *
     * @return array{score: float, size: int, qcTime: float}
     */
    private function probeHeicQuality(
        string $refPng,
        string $candPng,
        string $tmpOptimized,
        int $q,
        int $originalSize,
        callable $statusCallback,
        callable $statusEventCallback,
        float $totalQcTime,
        HeicCodec $heicCodec,
    ): array {
        $this->emitStatusEvent($statusEventCallback, 'encode', $q);
        $heicCodec->encodeFromPng($refPng, $tmpOptimized, $q);
        $this->emitStatusEvent($statusEventCallback, 'decode', $q);
        $heicCodec->decodeToPng($tmpOptimized, $candPng);

        $this->emitStatusEvent($statusEventCallback, 'score', $q);
        $qcStart      = microtime(true);
        $score        = $this->ssimulacra2->score($refPng, $candPng);
        $totalQcTime += microtime(true) - $qcStart;

        $size  = (int) filesize($tmpOptimized);
        $saved = $originalSize - $size;

        $statusCallback($q, $score, $saved);

        return ['score' => $score, 'size' => $size, 'qcTime' => $totalQcTime];
    }

    /** @param callable(string, int|null, float|null, int|null, string|null): void $statusEventCallback */
    private function emitStatusEvent(
        callable $statusEventCallback,
        string $phase,
        int|null $quality = null,
        float|null $score = null,
        int|null $savedBytes = null,
        string|null $decision = null,
    ): void {
        $statusEventCallback($phase, $quality, $score, $savedBytes, $decision);
    }

    private function copyAndVerifyMetadataStrict(string $source, string $dest): void
    {
        $this->exiftool->copyAllMetadata($source, $dest);
        $this->exiftool->forceOrientationTo1($dest);
        $this->exiftool->deleteDerivedDimensionTags($dest);
        $this->exiftool->restoreCriticalCaptureMetadata($source, $dest);

        $sourceMap = $this->exiftool->metadataMap($source);
        $destMap   = $this->exiftool->metadataMap($dest);

        $diffs = $this->metadataVerifier->diffs($sourceMap, $destMap);
        if ($diffs !== []) {
            $this->cleanup($dest);

            throw new RuntimeException(sprintf(
                "Metadata verification failed.\n%s",
                implode("\n", array_slice($diffs, 0, 20)),
            ));
        }

        // Ensure we actually forced orientation (some containers may refuse writes).
        if ($this->exiftool->orientation($dest) !== 1) {
            $this->cleanup($dest);

            throw new RuntimeException('Failed to force Orientation=1 after baking rotation.');
        }
    }

    private function moveFileOrFail(string $sourcePath, string $targetPath): void
    {
        if (@rename($sourcePath, $targetPath)) {
            return;
        }

        if (@copy($sourcePath, $targetPath)) {
            $this->cleanup($sourcePath);

            return;
        }

        throw new RuntimeException(sprintf(
            'Failed to move file from %s to %s.',
            $sourcePath,
            $targetPath,
        ));
    }

    private function cleanup(string ...$files): void
    {
        foreach ($files as $file) {
            if (! file_exists($file)) {
                continue;
            }

            @unlink($file);
        }
    }
}
