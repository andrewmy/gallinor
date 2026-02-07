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
        $filter      = $this->ffmpegFilterForOrientation($orientation);

        $args = [
            $this->ffmpegPath,
            '-hide_banner',
            '-loglevel',
            'error',
            '-i',
            $jpegPath,
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
