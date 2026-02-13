<?php

declare(strict_types=1);

namespace App\Images\Domain;

use App\Shared\Domain\Platform;
use RuntimeException;
use Symfony\Component\Process\Process;

final readonly class FfmpegImageNormalizer
{
    private string $ffmpegPath;

    public function __construct(
        private Platform $platform,
        private ExifMetadata $exiftool,
    ) {
        $this->ffmpegPath = $this->platform->findTool('ffmpeg');
    }

    /**
     * Render a JPEG to an upright PNG, baking EXIF orientation into pixels.
     *
     * @throws RuntimeException
     */
    public function jpegToUprightPng(string $jpegPath, string $targetPng): void
    {
        $orientation = $this->exiftool->orientation($jpegPath);
        $this->imageToUprightPngWithOrientation($jpegPath, $targetPng, $orientation);
    }

    /**
     * Render an image to an upright PNG using the specified EXIF orientation value (1..8).
     *
     * Useful when the orientation metadata exists on a different source file
     * (e.g. AVIF container) than the decoded pixels (e.g. PNG).
     *
     * @throws RuntimeException
     */
    public function imageToUprightPngWithOrientation(string $sourcePath, string $targetPng, int $orientation): void
    {
        $filter = $this->ffmpegFilterForOrientation($orientation);

        $args = [
            $this->ffmpegPath,
            '-hide_banner',
            '-loglevel',
            'error',
            // Keep rotation deterministic: we apply EXIF orientation explicitly via filter below.
            '-noautorotate',
            '-i',
            $sourcePath,
            '-frames:v',
            '1',
        ];

        if ($filter !== null) {
            $args[] = '-vf';
            $args[] = $filter;
        }

        $args[] = '-y';
        $args[] = $targetPng;

        $process = new Process($args);
        $process->setTimeout(null);
        $process->mustRun();
    }

    private function ffmpegFilterForOrientation(int $orientation): string|null
    {
        return match ($orientation) {
            1 => null,
            2 => 'hflip',
            3 => 'transpose=2,transpose=2',
            4 => 'vflip',
            5 => 'transpose=0',
            6 => 'transpose=1',
            7 => 'transpose=3',
            8 => 'transpose=2',
            default => null,
        };
    }
}
