<?php

declare(strict_types=1);

namespace App\Domain;

use RuntimeException;

use function escapeshellarg;
use function exec;
use function explode;
use function shell_exec;
use function sprintf;
use function trim;

class Exiftool
{
    private string $exiftoolPath;

    public function __construct(private Platform $platform)
    {
        $which = $this->platform->isWindows() ? 'where.exe' : 'which';

        $result = trim((string) shell_exec(sprintf('%s exiftool 2>/dev/null', $which)));

        if ($this->platform->isWindows()) {
            $lines  = explode("\n", $result);
            $result = trim($lines[0]);
        }

        if ($result === '') {
            throw new RuntimeException('Required tool not found: exiftool');
        }

        $this->exiftoolPath = $result;
    }

    public function path(): string
    {
        return $this->exiftoolPath;
    }

    /** @return array<string, true> Filenames (with path) to skip */
    public function findPortraitAndLivePhotos(string $dir): array
    {
        $cmd = sprintf(
            '%s -if %s -p %s -ext jpg -ext jpeg %s 2>/dev/null',
            escapeshellarg($this->exiftoolPath),
            escapeshellarg('$DepthMapData or $EmbeddedVideoFile'),
            escapeshellarg('$directory/$filename'),
            escapeshellarg($dir),
        );

        $result = [];
        exec($cmd, $result, $exitCode);

        if ($exitCode !== 0 || $result === []) {
            return [];
        }

        $skipSet = [];
        foreach ($result as $filename) {
            $skipSet[trim($filename)] = true;
        }

        return $skipSet;
    }
}
