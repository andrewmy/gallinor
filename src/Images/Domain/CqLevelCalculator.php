<?php

declare(strict_types=1);

namespace App\Images\Domain;

use RuntimeException;

use function file_exists;
use function filesize;
use function microtime;
use function rename;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const DIRECTORY_SEPARATOR;

final readonly class CqLevelCalculator
{
    private const float MIN_SSIM_SCORE = 85.0;
    private const int CQ_LEVEL_START   = 20;
    private const int CQ_LEVEL_END     = 2;
    private const int CQ_LEVEL_STEP    = 2;

    public function __construct(
        private ImageTools $imageTools,
    ) {
    }

    /**
     * Find optimal AVIF CQ level that meets quality threshold while staying under original size.
     *
     * @param callable(int, float, int): void|null $statusCallback Called with (cqLevel, score, saved) during quality search
     *
     * @return ImageProcessingResult|CalculationSkipReason The processing result, or a skip reason if not achieved
     *
     * @throws RuntimeException If encoding/scoring fails.
     */
    public function calculate(ImageFile $file, callable|null $statusCallback = null): ImageProcessingResult|CalculationSkipReason
    {
        $runId  = uniqid('gallinor-', true);
        $tmpDir = sys_get_temp_dir();

        $tmpAvif = $tmpDir . DIRECTORY_SEPARATOR . $runId . '.avif';
        $tmpPng  = $tmpDir . DIRECTORY_SEPARATOR . $runId . '.png';

        $totalQcTime = 0.0;

        for ($cqLevel = self::CQ_LEVEL_START; $cqLevel >= self::CQ_LEVEL_END; $cqLevel -= self::CQ_LEVEL_STEP) {
            $this->imageTools->encodeToAvif($file->path, $tmpAvif, $cqLevel);
            $this->imageTools->decodeAvifToPng($tmpAvif, $tmpPng);

            $qcStartTime  = microtime(true);
            $score        = $this->imageTools->ssimulacra2Score($file->path, $tmpPng);
            $totalQcTime += microtime(true) - $qcStartTime;

            $currentAvifSize = (int) filesize($tmpAvif);

            if ($statusCallback !== null) {
                $statusCallback($cqLevel, $score, $file->size - $currentAvifSize);
            }

            if ($score >= self::MIN_SSIM_SCORE) {
                if ($currentAvifSize >= $file->size) {
                    // AVIF not smaller, skip this file
                    $this->cleanup($tmpAvif, $tmpPng);

                    return CalculationSkipReason::AvifNotSmaller;
                }

                rename($tmpAvif, $file->optimizedPath());
                $this->cleanup($tmpPng);

                return new ImageProcessingResult(
                    avifSize: $currentAvifSize,
                    cqLevel: $cqLevel,
                    qualityScore: $score,
                    qcTime: $totalQcTime,
                );
            }

            if ($cqLevel <= self::CQ_LEVEL_END) {
                continue;
            }

            unlink($tmpAvif);
        }

        // Could not achieve target quality
        $this->cleanup($tmpAvif, $tmpPng);

        return CalculationSkipReason::QualityNotAchieved;
    }

    private function cleanup(string ...$files): void
    {
        foreach ($files as $file) {
            if (! file_exists($file)) {
                continue;
            }

            unlink($file);
        }
    }
}
