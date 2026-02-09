<?php

declare(strict_types=1);

namespace App\Images\Ui\Cli\Parallel;

use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Throwable;

use function filemtime;
use function is_dir;
use function scandir;
use function sprintf;
use function sys_get_temp_dir;
use function time;

use const DIRECTORY_SEPARATOR;

final class ParallelTempDirectoryManager
{
    private const string ROOT_DIR_NAME = 'gallinor-parallel';

    private static Filesystem|null $filesystem = null;

    public static function baseRoot(): string
    {
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . self::ROOT_DIR_NAME;
    }

    public static function workerRoot(string $workerId): string
    {
        return self::baseRoot() . DIRECTORY_SEPARATOR . $workerId;
    }

    public static function ensureDir(string $path): void
    {
        try {
            self::filesystem()->mkdir($path);
        } catch (Throwable) {
            throw new RuntimeException(sprintf('Failed to create directory: %s', $path));
        }

        if (! is_dir($path)) {
            throw new RuntimeException(sprintf('Failed to create directory: %s', $path));
        }
    }

    public static function pruneStaleWorkers(int $retentionSeconds): void
    {
        $baseRoot = self::baseRoot();
        if (! is_dir($baseRoot)) {
            return;
        }

        $entries = scandir($baseRoot);
        if ($entries === false) {
            return;
        }

        $cutoff = time() - $retentionSeconds;

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $candidate = $baseRoot . DIRECTORY_SEPARATOR . $entry;
            if (! is_dir($candidate)) {
                continue;
            }

            $mtime = filemtime($candidate);
            if ($mtime === false || $mtime >= $cutoff) {
                continue;
            }

            self::removeDir($candidate);
        }
    }

    public static function removeDir(string $path): void
    {
        self::filesystem()->remove($path);
    }

    private static function filesystem(): Filesystem
    {
        if (self::$filesystem instanceof Filesystem) {
            return self::$filesystem;
        }

        self::$filesystem = new Filesystem();

        return self::$filesystem;
    }
}
