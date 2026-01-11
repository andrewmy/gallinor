<?php

declare(strict_types=1);

namespace App\Domain;

use Symfony\Component\Process\Process;

use function explode;
use function trim;

class Exiftool
{
    private string $exiftoolPath;

    public function __construct(private Platform $platform)
    {
        $this->exiftoolPath = $this->platform->findTool('exiftool');
    }

    public function path(): string
    {
        return $this->exiftoolPath;
    }

    /** @return array<string, true> Filenames (with path) to skip */
    public function findPortraitAndLivePhotos(string $dir): array
    {
        $process = new Process([
            $this->exiftoolPath,
            '-if',
            '$DepthMapData or $EmbeddedVideoFile',
            '-p',
            '$directory/$filename',
            '-ext',
            'jpg',
            '-ext',
            'jpeg',
            $dir,
        ]);
        $process->run();

        if (! $process->isSuccessful()) {
            return [];
        }

        $output = $process->getOutput();
        if ($output === '') {
            return [];
        }

        $skipSet = [];
        foreach (explode("\n", trim($output)) as $filename) {
            $filename = trim($filename);
            if ($filename === '') {
                continue;
            }

            $skipSet[$filename] = true;
        }

        return $skipSet;
    }
}
