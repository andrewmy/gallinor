<?php

declare(strict_types=1);

namespace App\Ui\Cli;

use FilesystemIterator;
use Generator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;

use function dirname;
use function number_format;
use function pathinfo;
use function rtrim;
use function sprintf;
use function trim;

use const DIRECTORY_SEPARATOR;
use const PATHINFO_FILENAME;

final class CliHelper
{
    public function link(string $path, string|null $label = null): string
    {
        $label ??= $path;

        return sprintf("\e]8;;file://%s\e\\%s\e]8;;\e\\", $path, $label);
    }

    public function createProgressBar(OutputInterface $output, int $max, string $label = 'Processing'): ProgressBar
    {
        $progressBar = new ProgressBar($output, $max);
        $progressBar->setFormat(
            sprintf(" %s: %%current%%/%%max%% [%%bar%%] %%elapsed:6s%% / %%remaining:6s%% | %%status%%\n", $label),
        );
        $progressBar->setMessage('Starting...', 'status');
        $progressBar->setBarWidth(30);

        return $progressBar;
    }

    public function getAvifPath(string $jpegPath): string
    {
        return dirname($jpegPath) . DIRECTORY_SEPARATOR . pathinfo($jpegPath, PATHINFO_FILENAME) . '.avif';
    }

    public function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024 * 1024) {
            return sprintf('%.2f GB', $bytes / (1024 * 1024 * 1024));
        }

        if ($bytes >= 1024 * 1024) {
            return sprintf('%.1f MB', $bytes / (1024 * 1024));
        }

        return sprintf('%s KB', number_format((int) ($bytes / 1024), thousands_separator: ' '));
    }

    public function formatKb(int $kb): string
    {
        return $this->formatBytes($kb * 1024);
    }

    /**
     * Scan directories recursively and yield all files.
     *
     * @param list<string> $directories
     *
     * @return Generator<SplFileInfo>
     */
    public function scanDirectories(array $directories, OutputInterface $output): Generator
    {
        foreach ($directories as $directory) {
            $directory = rtrim(trim($directory, '"\' '), DIRECTORY_SEPARATOR);
            $output->writeln(sprintf('Scanning directory: %s', $this->link($directory)));

            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(directory: $directory, flags: FilesystemIterator::SKIP_DOTS),
            );

            foreach ($files as $file) {
                if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                    continue;
                }

                yield $file;
            }
        }
    }
}
