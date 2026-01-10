<?php

declare(strict_types=1);

namespace App\Domain;

use RuntimeException;

use function ceil;
use function escapeshellarg;
use function exec;
use function file_exists;
use function filesize;
use function implode;
use function sprintf;
use function trim;
use function unlink;

final readonly class ImageTools
{
    public string $avifencPath;
    public string $avifdecPath;
    public string $ssimulacra2Path;

    public function __construct(private Platform $platform)
    {
        $this->avifencPath     = $this->platform->findTool('avifenc');
        $this->avifdecPath     = $this->platform->findTool('avifdec');
        $this->ssimulacra2Path = $this->platform->findTool('ssimulacra2');
    }

    /**
     * Encode a JPEG to AVIF with the given CQ level.
     *
     * @throws RuntimeException If encoding fails.
     */
    public function encodeToAvif(string $sourcePath, string $targetPath, int $cqLevel): void
    {
        $cmd = sprintf(
            '%s -s 6 -j 8 -y 420 -d 10 -a tune=iq -a end-usage=q -a cq-level=%d %s %s 2>&1',
            escapeshellarg($this->avifencPath),
            $cqLevel,
            escapeshellarg($sourcePath),
            escapeshellarg($targetPath),
        );

        $output = [];
        exec($cmd, $output, $exitCode);

        if ($exitCode === 0) {
            return;
        }

        if (file_exists($targetPath)) {
            unlink($targetPath);
        }

        throw new RuntimeException(sprintf(
            "avifenc failed with exit code %d:\n%s",
            $exitCode,
            implode("\n", $output),
        ));
    }

    /**
     * Decode an AVIF to PNG for quality comparison.
     *
     * @throws RuntimeException If decoding fails.
     */
    public function decodeAvifToPng(string $avifPath, string $pngPath): void
    {
        $cmd = sprintf(
            '%s --png-compress 0 %s %s 2>&1',
            escapeshellarg($this->avifdecPath),
            escapeshellarg($avifPath),
            escapeshellarg($pngPath),
        );

        $output = [];
        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            throw new RuntimeException(sprintf(
                "avifdec failed with exit code %d:\n%s",
                $exitCode,
                implode("\n", $output),
            ));
        }
    }

    /**
     * Calculate the ssimulacra2 score between the original and compressed image.
     *
     * @return float The ssimulacra2 score (higher is better, typically 85+ is good)
     *
     * @throws RuntimeException If scoring fails.
     */
    public function ssimulacra2Score(string $originalPath, string $decodedPngPath): float
    {
        $cmd = sprintf(
            '%s %s %s 2>&1',
            escapeshellarg($this->ssimulacra2Path),
            escapeshellarg($originalPath),
            escapeshellarg($decodedPngPath),
        );

        $output = [];
        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            throw new RuntimeException(sprintf(
                "ssimulacra2 failed with exit code %d:\n%s",
                $exitCode,
                implode("\n", $output),
            ));
        }

        return (float) trim($output[0] ?? '0');
    }

    /**
     * Get the size of a file in KB.
     */
    public function fileSizeKb(string $path): int
    {
        return (int) ceil(filesize($path) / 1024);
    }
}
