<?php

declare(strict_types=1);

namespace App\Ui\Cli\Videos;

use App\Domain\VideoFile;
use App\Ui\Cli\CliHelper;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

use function assert;
use function count;
use function filesize;
use function microtime;
use function number_format;
use function rename;
use function rtrim;
use function sprintf;
use function str_ends_with;
use function str_replace;
use function trim;

use const DIRECTORY_SEPARATOR;
use const PHP_EOL;

#[AsCommand(name: 'videos:rename', description: 'Rename optimal video files to replace originals')]
final class Rename extends Command
{
    public function __construct(private readonly CliHelper $cliHelper)
    {
        parent::__construct();
    }

    /** @param list<string> $directories */
    public function __invoke(
        OutputInterface $output,
        #[Option]
        bool $dryRun = false,
        #[Argument]
        array $directories = [],
    ): int {
        $output->writeln(sprintf('<info>Dry run: %s</info>%s', $dryRun ? 'Yes' : 'No', PHP_EOL));

        $startTime = microtime(true);

        $filesToRename = [];
        $totalOldSize  = 0;
        $totalNewSize  = 0;

        foreach ($directories as $directory) {
            $directory = rtrim(trim($directory, '"\''), DIRECTORY_SEPARATOR);
            $output->writeln(sprintf('Scanning directory: %s', $this->cliHelper->link($directory)));

            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(directory: $directory, flags: FilesystemIterator::SKIP_DOTS),
            );

            foreach ($files as $file) {
                assert($file instanceof SplFileInfo);
                if (
                    ! $file->isFile()
                    || ! str_ends_with($file->getFilename(), '.' . VideoFile::OPTIMAL_SUFFIX . '.mp4')
                ) {
                    continue;
                }

                $optimalPath  = $file->getPathname();
                $originalPath = str_replace(
                    '.' . VideoFile::OPTIMAL_SUFFIX . '.mp4',
                    '.mp4',
                    $optimalPath,
                );

                $oldSize       = (int) filesize($originalPath);
                $newSize       = (int) filesize($optimalPath);
                $totalOldSize += $oldSize;
                $totalNewSize += $newSize;

                $filesToRename[] = [
                    'optimal' => $optimalPath,
                    'original' => $originalPath,
                    'oldSize' => $oldSize,
                    'newSize' => $newSize,
                ];

                $output->writeln(sprintf(
                    '  %s (%s KB) => %s (%s KB)',
                    $this->cliHelper->link($optimalPath),
                    number_format($newSize / 1024, thousands_separator: ' '),
                    $this->cliHelper->link($originalPath),
                    number_format($oldSize / 1024, thousands_separator: ' '),
                ));
            }
        }

        $gatherTime = microtime(true);
        $output->writeln(sprintf('<info>Gather time: %.3fs</info>', $gatherTime - $startTime));

        if ($dryRun) {
            $output->writeln('');
            $output->writeln(sprintf(
                "Video Summary:\n  Found: %d\n  To rename: %d\n  Size before: %s KB\n  Size after: %s KB\n  Space to free: %s KB",
                count($filesToRename),
                count($filesToRename),
                number_format($totalOldSize / 1024, thousands_separator: ' '),
                number_format($totalNewSize / 1024, thousands_separator: ' '),
                number_format(($totalOldSize - $totalNewSize) / 1024, thousands_separator: ' '),
            ));

            return self::SUCCESS;
        }

        $renamed = 0;
        $errored = 0;

        foreach ($filesToRename as $file) {
            try {
                rename($file['optimal'], $file['original']);
                $renamed++;
            } catch (Throwable $exception) {
                $output->writeln(sprintf('<error>Failed to rename %s: %s</error>', $file['optimal'], $exception->getMessage()));
                $errored++;
            }
        }

        $endTime = microtime(true);

        $output->writeln('');
        $output->writeln(sprintf(
            "Video Summary:\n  Found: %d\n  Renamed: %d\n  Errored: %d\n  Size before: %s KB\n  Size after: %s KB\n  Space freed: %s KB",
            count($filesToRename),
            $renamed,
            $errored,
            number_format($totalOldSize / 1024, thousands_separator: ' '),
            number_format($totalNewSize / 1024, thousands_separator: ' '),
            number_format(($totalOldSize - $totalNewSize) / 1024, thousands_separator: ' '),
        ));
        $output->writeln(sprintf(
            "\n<info>Timing:\n  Gather: %.3fs\n  Rename: %.3fs\n  Total: %.3fs</info>",
            $gatherTime - $startTime,
            $endTime - $gatherTime,
            $endTime - $startTime,
        ));

        return self::SUCCESS;
    }
}
