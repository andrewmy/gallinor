<?php

declare(strict_types=1);

namespace App\Images\Domain;

use App\Shared\Domain\Platform;
use JsonException;
use RuntimeException;
use Symfony\Component\Process\Process;

use function explode;
use function is_array;
use function is_numeric;
use function is_scalar;
use function is_string;
use function json_decode;
use function json_encode;
use function trim;

use const JSON_THROW_ON_ERROR;

final readonly class Exiftool implements ExifMetadata
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

    /**
     * Read numeric EXIF orientation (1..8).
     *
     * Defaults to 1 if not present/parseable.
     */
    public function orientation(string $path): int
    {
        $process = new Process([
            $this->exiftoolPath,
            '-s',
            '-s',
            '-s',
            '-n',
            '-Orientation#',
            $path,
        ]);
        $process->run();

        if (! $process->isSuccessful()) {
            return 1;
        }

        $value = trim($process->getOutput());
        if (! is_numeric($value)) {
            return 1;
        }

        $orientation = (int) $value;
        if ($orientation < 1 || $orientation > 8) {
            return 1;
        }

        return $orientation;
    }

    /** Copy metadata from one file to another (overwrites destination metadata). */
    public function copyAllMetadata(string $from, string $to): void
    {
        $process = new Process([
            $this->exiftoolPath,
            '-overwrite_original',
            '-P',
            '-tagsFromFile',
            $from,
            '-all:all',
            $to,
        ]);
        $process->mustRun();
    }

    public function forceOrientationTo1(string $path): void
    {
        $process = new Process([
            $this->exiftoolPath,
            '-overwrite_original',
            '-n',
            '-Orientation=1',
            $path,
        ]);
        $process->mustRun();
    }

    /**
     * Delete EXIF dimension tags that can become stale when we bake rotation.
     */
    public function deleteDerivedDimensionTags(string $path): void
    {
        $process = new Process([
            $this->exiftoolPath,
            '-overwrite_original',
            '-ExifImageWidth=',
            '-ExifImageHeight=',
            '-ImageWidth=',
            '-ImageHeight=',
            $path,
        ]);
        $process->run();
    }

    /**
     * Dump metadata for strict verification.
     *
     * @return array<string, string> Map "GROUP:Tag" => value (stringified)
     */
    public function metadataMap(string $path): array
    {
        $process = new Process([
            $this->exiftoolPath,
            '-G1',
            '-a',
            '-u',
            '-s',
            '-json',
            $path,
        ]);
        $process->mustRun();

        try {
            $decoded = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Failed to parse exiftool JSON output: ' . $exception->getMessage());
        }

        if (! is_array($decoded) || ! isset($decoded[0]) || ! is_array($decoded[0])) {
            throw new RuntimeException('Failed to parse exiftool JSON output.');
        }

        $map = [];
        foreach ($decoded[0] as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            if (is_array($value)) {
                try {
                    $map[$key] = json_encode($value, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    continue;
                }

                continue;
            }

            if ($value === null) {
                continue;
            }

            if (! is_scalar($value)) {
                continue;
            }

            $map[$key] = (string) $value;
        }

        return $map;
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
