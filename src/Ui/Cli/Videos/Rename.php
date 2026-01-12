<?php

declare(strict_types=1);

namespace App\Ui\Cli\Videos;

use App\Domain\FilesystemScanner;
use App\Domain\VideoFile;
use App\Ui\Cli\CliHelper;
use App\Ui\Cli\Summary\Timing;
use App\Ui\Cli\Summary\VideoSummary;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

use function count;
use function filesize;
use function microtime;
use function rename;
use function sprintf;
use function str_ends_with;
use function str_replace;

use const PHP_EOL;

#[AsCommand(name: 'videos:rename', description: 'Rename optimal video files to replace originals')]
final class Rename extends Command
{
    public function __construct(
        private readonly CliHelper $cliHelper,
        private readonly FilesystemScanner $scanner,
    ) {
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

        foreach ($this->scanner->scanDirectories($directories) as $file) {
            if (! str_ends_with($file->getFilename(), '.' . VideoFile::OPTIMAL_SUFFIX . '.mp4')) {
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
                '  %s (%s) => %s (%s)',
                $this->cliHelper->link($optimalPath),
                $this->cliHelper->formatBytes($newSize),
                $this->cliHelper->link($originalPath),
                $this->cliHelper->formatBytes($oldSize),
            ));
        }

        $gatherTime = microtime(true);
        $output->writeln(sprintf('<info>Gather time: %.3fs</info>', $gatherTime - $startTime));

        if ($dryRun) {
            $output->writeln('');
            new VideoSummary(
                sizeBefore: $totalOldSize,
                sizeAfter: $totalNewSize,
                found: count($filesToRename),
            )->print($output, $this->cliHelper);

            $output->writeln('');
            new Timing(
                total: $gatherTime - $startTime,
                gather: $gatherTime - $startTime,
            )->print($output);

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
        new VideoSummary(
            sizeBefore: $totalOldSize,
            sizeAfter: $totalNewSize,
            found: count($filesToRename),
            renamed: $renamed,
            errored: $errored,
        )->print($output, $this->cliHelper);

        $output->writeln('');
        new Timing(
            total: $endTime - $startTime,
            gather: $gatherTime - $startTime,
            rename: $endTime - $gatherTime,
        )->print($output);

        return self::SUCCESS;
    }
}
