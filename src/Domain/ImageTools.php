<?php

declare(strict_types=1);

namespace App\Domain;

use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

use function ceil;
use function filesize;
use function trim;

final readonly class ImageTools
{
    public string $avifencPath;
    public string $avifdecPath;
    public string $ssimulacra2Path;

    public function __construct(
        private Platform $platform,
        private readonly Filesystem $fs = new Filesystem(),
    ) {
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
        $process = new Process([
            $this->avifencPath,
            '-s', '6',
            '-j', '8',
            '-y', '420',
            '-d', '10',
            '-a', 'tune=iq',
            '-a', 'end-usage=q',
            '-a', "cq-level=$cqLevel",
            $sourcePath,
            $targetPath,
        ]);

        $process->run();

        if ($process->isSuccessful()) {
            return;
        }

        if ($this->fs->exists($targetPath)) {
            $this->fs->remove($targetPath);
        }

        throw new RuntimeException(sprintf(
            "avifenc failed with exit code %d:\n%s",
            $process->getExitCode(),
            $process->getErrorOutput(),
        ));
    }

    /**
     * Decode an AVIF to PNG for quality comparison.
     *
     * @throws RuntimeException If decoding fails.
     */
    public function decodeAvifToPng(string $avifPath, string $pngPath): void
    {
        $process = new Process([
            $this->avifdecPath,
            '--png-compress', '0',
            $avifPath,
            $pngPath,
        ]);

        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(sprintf(
                "avifdec failed with exit code %d:\n%s",
                $process->getExitCode(),
                $process->getErrorOutput(),
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
        $process = new Process([
            $this->ssimulacra2Path,
            $originalPath,
            $decodedPngPath,
        ]);

        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(sprintf(
                "ssimulacra2 failed with exit code %d:\n%s",
                $process->getExitCode(),
                $process->getErrorOutput(),
            ));
        }

        return (float) trim($process->getOutput());
    }

    /**
     * Get the size of a file in KB.
     */
    public function fileSizeKb(string $path): int
    {
        return (int) ceil(filesize($path) / 1024);
    }
}
