<?php

declare(strict_types=1);

namespace App\Images\Domain;

use App\Shared\Domain\Platform;
use RuntimeException;
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
            '-s',
            '6',
            '-j',
            '8',
            '-y',
            '420',
            '-d',
            '10',
            '-a',
            'tune=iq',
            '-a',
            'end-usage=q',
            '-a',
            'cq-level=' . $cqLevel,
            $sourcePath,
            $targetPath,
        ]);
        $process->mustRun();
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
            '--png-compress',
            '0',
            $avifPath,
            $pngPath,
        ]);
        $process->mustRun();
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
        $command = $this->platform->isWindows()
            ? [$this->ssimulacra2Path, 'image', $originalPath, $decodedPngPath]
            : [$this->ssimulacra2Path, $originalPath, $decodedPngPath];

        $process = new Process($command);
        $process->mustRun();

        $output = trim($process->getOutput());
        $score = str_starts_with($output, 'Score: ') ? substr($output, 7) : $output;

        return (float) $score;
    }

    /**
     * Get the size of a file in KB.
     */
    public function fileSizeKb(string $path): int
    {
        return (int) ceil(filesize($path) / 1024);
    }
}
