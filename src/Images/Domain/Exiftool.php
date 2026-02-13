<?php

declare(strict_types=1);

namespace App\Images\Domain;

use App\Shared\Domain\Platform;
use JsonException;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

use function array_merge;
use function explode;
use function is_array;
use function is_numeric;
use function is_scalar;
use function is_string;
use function json_decode;
use function json_encode;
use function preg_match;
use function sprintf;
use function str_contains;
use function strtolower;
use function trim;
use function usleep;

use const JSON_THROW_ON_ERROR;

final readonly class Exiftool implements ExifMetadata
{
    private const int WRITE_MAX_ATTEMPTS             = 2;
    private const int WRITE_RETRY_DELAY_MICROSECONDS = 200_000;

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
        $process = new Process($this->command(
            [
                '-m',
                '-s',
                '-s',
                '-s',
                '-n',
                '-Orientation#',
                $path,
            ],
            [$path],
        ));
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
        $this->runWriteCommand(
            [
                '-m',
                '-overwrite_original',
                '-P',
                '-tagsFromFile',
                $from,
                '-all:all',
                $to,
            ],
            sprintf('copy metadata from %s to %s', $from, $to),
            pathArguments: [$from, $to],
        );
    }

    public function forceOrientationTo1(string $path): void
    {
        $this->runWriteCommand(
            [
                '-m',
                '-overwrite_original',
                '-n',
                '-Orientation=1',
                $path,
            ],
            sprintf('set Orientation=1 for %s', $path),
            pathArguments: [$path],
        );
    }

    /**
     * Delete EXIF dimension tags that can become stale when we bake rotation.
     */
    public function deleteDerivedDimensionTags(string $path): void
    {
        $this->runWriteCommand(
            [
                '-overwrite_original',
                '-ExifImageWidth=',
                '-ExifImageHeight=',
                '-ImageWidth=',
                '-ImageHeight=',
                $path,
            ],
            sprintf('delete derived dimension tags for %s', $path),
            throwOnFailure: false,
            pathArguments: [$path],
        );
    }

    /**
     * Dump metadata for strict verification.
     *
     * @return array<string, string> Map "GROUP:Tag" => value (stringified)
     */
    public function metadataMap(string $path): array
    {
        $process = new Process($this->command(
            [
                '-m',
                '-G1',
                '-a',
                '-u',
                '-s',
                '-json',
                $path,
            ],
            [$path],
        ));
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
        $process = new Process($this->command(
            [
                '-if',
                '$DepthMapData or $EmbeddedVideoFile',
                '-p',
                '$directory/$filename',
                '-ext',
                'jpg',
                '-ext',
                'jpeg',
                $dir,
            ],
            [$dir],
        ));
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

    /**
     * @param list<string> $command
     * @param list<string> $pathArguments
     */
    private function runWriteCommand(array $command, string $operation, bool $throwOnFailure = true, array $pathArguments = []): void
    {
        for ($attempt = 1; $attempt <= self::WRITE_MAX_ATTEMPTS; $attempt++) {
            $process = new Process($this->command($command, $pathArguments));

            try {
                $process->mustRun();

                return;
            } catch (ProcessFailedException $exception) {
                $stdout = trim($process->getOutput());
                $stderr = trim($process->getErrorOutput());

                if ($attempt < self::WRITE_MAX_ATTEMPTS && $this->isTransientWriteFailure($stdout, $stderr)) {
                    usleep(self::WRITE_RETRY_DELAY_MICROSECONDS);
                    continue;
                }

                if (! $throwOnFailure) {
                    return;
                }

                throw new RuntimeException(
                    $this->formatWriteFailureMessage($operation, $stdout, $stderr),
                    0,
                    $exception,
                );
            }
        }
    }

    /**
     * @param list<string> $arguments
     * @param list<string> $pathArguments
     *
     * @return list<string>
     */
    private function command(array $arguments, array $pathArguments = []): array
    {
        $command = [$this->exiftoolPath];
        if ($this->shouldUseUtf8FilenameCharset($pathArguments)) {
            $command[] = '-charset';
            $command[] = 'filename=UTF8';
        }

        return array_merge($command, $arguments);
    }

    /** @param list<string> $pathArguments */
    private function shouldUseUtf8FilenameCharset(array $pathArguments): bool
    {
        if (! $this->platform->isWindows()) {
            return false;
        }

        foreach ($pathArguments as $pathArgument) {
            if ($pathArgument === '') {
                continue;
            }

            if (preg_match('//u', $pathArgument) !== 1) {
                return false;
            }
        }

        return true;
    }

    private function isTransientWriteFailure(string $stdout, string $stderr): bool
    {
        $output = strtolower($stdout . "\n" . $stderr);

        return str_contains($output, 'error renaming temporary file')
            || str_contains($output, 'error creating file')
            || str_contains($output, 'resource busy')
            || str_contains($output, 'temporarily unavailable')
            || str_contains($output, 'operation not permitted');
    }

    private function formatWriteFailureMessage(string $operation, string $stdout, string $stderr): string
    {
        if ($stderr !== '') {
            return sprintf('exiftool failed to %s: %s', $operation, $stderr);
        }

        if ($stdout !== '') {
            return sprintf('exiftool failed to %s: %s', $operation, $stdout);
        }

        return sprintf('exiftool failed to %s.', $operation);
    }
}
