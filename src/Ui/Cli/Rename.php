<?php

declare(strict_types=1);

namespace App\Ui\Cli;

use App\Domain\VideoFile;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

use function assert;
use function filesize;
use function rename;
use function rtrim;
use function sprintf;
use function str_ends_with;
use function str_replace;
use function trim;

use const DIRECTORY_SEPARATOR;

#[AsCommand(name: 'rename', description: 'Rename optimal files to replace originals')]
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
        $output->writeln(sprintf('<info>Dry run: %s</info>', $dryRun ? 'Yes' : 'No'));

        $totalOldSize = 0;
        $totalNewSize = 0;
        foreach ($directories as $directory) {
            $directory = rtrim(trim($directory, '"\' '), DIRECTORY_SEPARATOR);
            $output->writeln(sprintf("\nDirectory: %s", $this->cliHelper->link($directory)));
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

                $newFilename = str_replace(
                    '.' . VideoFile::OPTIMAL_SUFFIX . '.mp4',
                    '.mp4',
                    $file->getPathname(),
                );
                $oldSize     = filesize($newFilename);
                $newSize     = filesize($file->getPathname());
                $output->writeln(sprintf(
                    'File: %s (%.1f MB) => %s (%.1f MB)',
                    $this->cliHelper->link($file->getPathname()),
                    $newSize / 1024 / 1024,
                    $this->cliHelper->link($newFilename),
                    $oldSize / 1024 / 1024,
                ));

                $totalOldSize += $oldSize;
                $totalNewSize += $newSize;

                if ($dryRun) {
                    continue;
                }

                rename($file->getPathname(), $newFilename);
            }
        }

        $output->writeln(sprintf(
            "\nTotal: %.1f - %.1f = %.1f MB savings",
            $totalOldSize / 1024 / 1024,
            $totalNewSize / 1024 / 1024,
            ($totalOldSize - $totalNewSize) / 1024 / 1024,
        ));

        return self::SUCCESS;
    }
}
