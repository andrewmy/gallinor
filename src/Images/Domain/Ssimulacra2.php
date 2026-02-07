<?php

declare(strict_types=1);

namespace App\Images\Domain;

use App\Shared\Domain\Platform;
use RuntimeException;
use Symfony\Component\Process\Process;

use function ceil;
use function filesize;
use function str_starts_with;
use function substr;
use function trim;

final readonly class Ssimulacra2
{
    public function __construct(
        private Platform $platform,
        private string $ssimulacra2Path,
    ) {
    }

    public static function fromPlatform(Platform $platform): self
    {
        return new self($platform, $platform->findTool('ssimulacra2'));
    }

    /**
     * Calculate the ssimulacra2 score between two images.
     *
     * @return float The ssimulacra2 score (higher is better)
     *
     * @throws RuntimeException If scoring fails.
     */
    public function score(string $referenceImagePath, string $candidateImagePath): float
    {
        $command = $this->platform->isWindows()
            ? [$this->ssimulacra2Path, 'image', $referenceImagePath, $candidateImagePath]
            : [$this->ssimulacra2Path, $referenceImagePath, $candidateImagePath];

        $process = new Process($command);
        $process->mustRun();

        $output = trim($process->getOutput());
        $score  = str_starts_with($output, 'Score: ') ? substr($output, 7) : $output;

        return (float) $score;
    }

    public function fileSizeKb(string $path): int
    {
        return (int) ceil(filesize($path) / 1024);
    }
}
