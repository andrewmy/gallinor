<?php

declare(strict_types=1);

namespace App\Images\Domain;

use RuntimeException;

use function array_slice;
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
    private const float JPEG_MIN_SSIM_SCORE      = 85.0;
    private const float MIGRATION_MIN_SSIM_SCORE = 85.0;

    private const int AVIF_CQ_START = 20;
    private const int AVIF_CQ_END   = 2;
    private const int AVIF_CQ_STEP  = 2;

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
     * @param callable(int, float, int): void|null $statusCallback Called with (qualityValue, score, savedBytes)
     */
    public function optimizeJpeg(ImageFile $file, ImageCodec $codec, callable|null $statusCallback = null): ImageProcessingResult|CalculationSkipReason
    {
        $runId  = uniqid('gallinor-', true);
        $tmpDir = sys_get_temp_dir();

        $refPng  = $tmpDir . DIRECTORY_SEPARATOR . $runId . '-ref.png';
        $candPng = $tmpDir . DIRECTORY_SEPARATOR . $runId . '-cand.png';

        $format       = $codec->format();
        $tmpOptimized = $tmpDir . DIRECTORY_SEPARATOR . $runId . '.' . $format->extension();
        $finalPath    = $file->optimizedPathFor($format);

        try {
            $this->normalizer->jpegToUprightPng($file->path, $refPng);

            $totalQcTime = 0.0;

            if ($codec->format() === ImageFormat::Avif) {
                for ($cq = self::AVIF_CQ_START; $cq >= self::AVIF_CQ_END; $cq -= self::AVIF_CQ_STEP) {
                    $codec->encodeFromPng($refPng, $tmpOptimized, $cq);
                    $codec->decodeToPng($tmpOptimized, $candPng);

                    $qcStart      = microtime(true);
                    $score        = $this->ssimulacra2->score($refPng, $candPng);
                    $totalQcTime += microtime(true) - $qcStart;

                    $size  = (int) filesize($tmpOptimized);
                    $saved = $file->size - $size;

                    if ($statusCallback !== null) {
                        $statusCallback($cq, $score, $saved);
                    }

                    // Size only gets worse as quality increases from here.
                    if ($size >= $file->size) {
                        return CalculationSkipReason::ReplacementNotSmaller;
                    }

                    if ($score < self::JPEG_MIN_SSIM_SCORE) {
                        $this->cleanup($tmpOptimized);
                        continue;
                    }

                    rename($tmpOptimized, $finalPath);
                    $this->copyAndVerifyMetadataStrict($file->path, $finalPath);

                    return new ImageProcessingResult(
                        format: $codec->format(),
                        optimizedSize: $size,
                        originalSize: $file->size,
                        qualityValue: $cq,
                        qualityLabel: $codec->qualityLabel(),
                        qualityScore: $score,
                        qcTime: $totalQcTime,
                    );
                }

                return CalculationSkipReason::QualityNotAchieved;
            }

            if (! $codec instanceof HeicCodec) {
                throw new RuntimeException('Invalid codec: expected HEIC codec.');
            }

            // HEIC quality search: increase q until threshold met, then refine to minimum passing q.
            $found = $this->findMinimumHeicQuality(
                refPng: $refPng,
                candPng: $candPng,
                tmpOptimized: $tmpOptimized,
                minScore: self::JPEG_MIN_SSIM_SCORE,
                statusCallback: $statusCallback,
                totalQcTime: $totalQcTime,
                originalSize: $file->size,
                requireSmallerThanOriginal: true,
                heicCodec: $codec,
            );

            if ($found === null) {
                return CalculationSkipReason::QualityNotAchieved;
            }

            if ($found['size'] >= $file->size) {
                return CalculationSkipReason::ReplacementNotSmaller;
            }

            rename($tmpOptimized, $finalPath);
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
     * Migrate an existing AVIF into HEIC with minimal additional loss.
     *
     * @param callable(int, float, int): void|null $statusCallback Called with (q, score, savedBytesVsAvif)
     */
    public function migrateAvifToHeic(
        string $avifPath,
        string $targetHeicPath,
        AvifCodec $avifCodec,
        HeicCodec $heicCodec,
        callable|null $statusCallback = null,
    ): ImageProcessingResult|CalculationSkipReason {
        $runId  = uniqid('gallinor-', true);
        $tmpDir = sys_get_temp_dir();

        $refPng  = $tmpDir . DIRECTORY_SEPARATOR . $runId . '-ref.png';
        $candPng = $tmpDir . DIRECTORY_SEPARATOR . $runId . '-cand.png';

        $tmpHeic = $tmpDir . DIRECTORY_SEPARATOR . $runId . '.heic';

        try {
            $avifCodec->decodeToPng($avifPath, $refPng);

            $avifSize = (int) filesize($avifPath);

            $found = $this->findMinimumHeicQuality(
                refPng: $refPng,
                candPng: $candPng,
                tmpOptimized: $tmpHeic,
                minScore: self::MIGRATION_MIN_SSIM_SCORE,
                statusCallback: $statusCallback,
                totalQcTime: 0.0,
                originalSize: $avifSize,
                requireSmallerThanOriginal: false,
                heicCodec: $heicCodec,
            );

            if ($found === null) {
                return CalculationSkipReason::QualityNotAchieved;
            }

            rename($tmpHeic, $targetHeicPath);
            $this->copyAndVerifyMetadataStrict($avifPath, $targetHeicPath);

            return new ImageProcessingResult(
                format: ImageFormat::Heic,
                optimizedSize: $found['size'],
                originalSize: $avifSize,
                qualityValue: $found['quality'],
                qualityLabel: $heicCodec->qualityLabel(),
                qualityScore: $found['score'],
                qcTime: $found['qcTime'],
            );
        } finally {
            $this->cleanup($refPng, $candPng, $tmpHeic);
        }
    }

    /**
     * @param callable(int, float, int): void|null $statusCallback
     * @param float                                $totalQcTime    Mutable accumulator passed by value and returned via array
     *
     * @return array{quality: int, score: float, size: int, qcTime: float}|null
     */
    private function findMinimumHeicQuality(
        string $refPng,
        string $candPng,
        string $tmpOptimized,
        float $minScore,
        callable|null $statusCallback,
        float $totalQcTime,
        int $originalSize,
        bool $requireSmallerThanOriginal,
        HeicCodec $heicCodec,
    ): array|null {
        $lastFailQuality  = null;
        $firstPassQuality = null;
        $fallbackTooBig   = null;

        for ($q = 40; $q <= 100; $q += 10) {
            $probe       = $this->probeHeicQuality($refPng, $candPng, $tmpOptimized, $q, $originalSize, $statusCallback, $totalQcTime, $heicCodec);
            $score       = $probe['score'];
            $size        = $probe['size'];
            $totalQcTime = $probe['qcTime'];

            if ($requireSmallerThanOriginal && $size >= $originalSize) {
                if ($score < $minScore) {
                    // Lower quality won't improve the score; higher quality won't improve the size.
                    return ['quality' => $q, 'score' => $score, 'size' => $size, 'qcTime' => $totalQcTime];
                }

                // We might still find a smaller passing quality below this bound.
                $fallbackTooBig   = ['quality' => $q, 'score' => $score, 'size' => $size, 'qcTime' => $totalQcTime];
                $firstPassQuality = $q;
                break;
            }

            if ($score >= $minScore) {
                $firstPassQuality = $q;
                break;
            }

            $lastFailQuality = $q;
            $this->cleanup($tmpOptimized);
        }

        if ($firstPassQuality === null) {
            return null;
        }

        $start = $lastFailQuality ?? 0;
        $best  = null;

        for ($q = $start; $q <= $firstPassQuality; $q += 2) {
            $probe       = $this->probeHeicQuality($refPng, $candPng, $tmpOptimized, $q, $originalSize, $statusCallback, $totalQcTime, $heicCodec);
            $score       = $probe['score'];
            $size        = $probe['size'];
            $totalQcTime = $probe['qcTime'];

            if ($requireSmallerThanOriginal && $size >= $originalSize) {
                // Higher quality will only increase size.
                $this->cleanup($tmpOptimized);
                break;
            }

            if ($score < $minScore) {
                $this->cleanup($tmpOptimized);
                continue;
            }

            $best = ['quality' => $q, 'score' => $score, 'size' => $size, 'qcTime' => $totalQcTime];
            break;
        }

        return $best ?? $fallbackTooBig;
    }

    /**
     * @param callable(int, float, int): void|null $statusCallback Called with (q, score, savedBytes)
     *
     * @return array{score: float, size: int, qcTime: float}
     */
    private function probeHeicQuality(
        string $refPng,
        string $candPng,
        string $tmpOptimized,
        int $q,
        int $originalSize,
        callable|null $statusCallback,
        float $totalQcTime,
        HeicCodec $heicCodec,
    ): array {
        $heicCodec->encodeFromPng($refPng, $tmpOptimized, $q);
        $heicCodec->decodeToPng($tmpOptimized, $candPng);

        $qcStart      = microtime(true);
        $score        = $this->ssimulacra2->score($refPng, $candPng);
        $totalQcTime += microtime(true) - $qcStart;

        $size  = (int) filesize($tmpOptimized);
        $saved = $originalSize - $size;

        if ($statusCallback !== null) {
            $statusCallback($q, $score, $saved);
        }

        return ['score' => $score, 'size' => $size, 'qcTime' => $totalQcTime];
    }

    private function copyAndVerifyMetadataStrict(string $source, string $dest): void
    {
        $this->exiftool->copyAllMetadata($source, $dest);
        $this->exiftool->forceOrientationTo1($dest);
        $this->exiftool->deleteDerivedDimensionTags($dest);

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
