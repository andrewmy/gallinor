<?php

declare(strict_types=1);

namespace App\Images\Ui\Cli;

use App\Images\Domain\AvifMigrationPlanner;
use App\Shared\Domain\FilesystemScanner;
use App\Shared\Ui\Cli\CliHelper;
use App\Shared\Ui\Cli\Timing;
use RuntimeException;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

use function basename;
use function count;
use function dirname;
use function file_exists;
use function filesize;
use function is_file;
use function microtime;
use function number_format;
use function pathinfo;
use function sprintf;
use function unlink;

use const DIRECTORY_SEPARATOR;
use const PATHINFO_FILENAME;

#[AsCommand(name: 'images:remove-avifs', description: 'Remove AVIFs after AVIF→HEIC migration (only if sibling .heic exists)')]
final class RemoveAvifs extends Command
{
    public function __construct(
        private readonly CliHelper $cliHelper,
        private readonly FilesystemScanner $scanner,
        private readonly Timing $timing,
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
        $startTime = $this->cliHelper->startCommand($output, $dryRun, $this->timing);

        $plan       = (new AvifMigrationPlanner($this->scanner))->plan($directories);
        $candidates = $plan->alreadyMigratedAvifs;

        $output->writeln(sprintf('<info>Found %d AVIFs removable</info>', count($candidates)));

        if ($dryRun) {
            foreach ($candidates as $path) {
                $output->writeln(sprintf('  Will remove: %s', $this->cliHelper->link($path)));
            }

            return self::SUCCESS;
        }

        $removed    = 0;
        $errored    = 0;
        $missing    = 0;
        $freedBytes = 0;
        $heicBytes  = 0;

        foreach ($candidates as $path) {
            if (! is_file($path)) {
                $missing++;
                $this->reportMissingAvif($output, $path);

                continue;
            }

            try {
                $avifSize = @filesize($path);
                if ($avifSize === false) {
                    throw new RuntimeException(sprintf('Cannot read AVIF size: %s', $path));
                }

                $heicPath = dirname($path) . DIRECTORY_SEPARATOR . pathinfo($path, PATHINFO_FILENAME) . '.heic';
                $heicSize = 0;
                if (file_exists($heicPath)) {
                    $heicSize = @filesize($heicPath);
                    $heicSize = $heicSize === false ? 0 : $heicSize;
                }

                if (! @unlink($path)) {
                    if (! file_exists($path)) {
                        $missing++;
                        $this->reportMissingAvif($output, $path);

                        continue;
                    }

                    throw new RuntimeException(sprintf('Cannot remove AVIF: %s', $path));
                }

                $removed++;

                $freedBytes += (int) $avifSize;
                $heicBytes  += $heicSize;
            } catch (Throwable $exception) {
                $errored++;
                $output->writeln(sprintf('<error>%s: %s</error>', basename($path), $exception->getMessage()));
            }
        }

        $endTime = microtime(true);

        $deltaBytes = $freedBytes - $heicBytes;
        $deltaSign  = $deltaBytes >= 0 ? '+' : '-';
        $deltaAbs   = $deltaBytes >= 0 ? $deltaBytes : -$deltaBytes;

        $output->writeln(sprintf(
            "\nSummary:\n  Removed: %d\n  Skipped missing: %d\n  Errored: %d\n  Freed: %s (%s bytes)\n  Δ vs HEIC: %s%s (%s bytes)\n  Time: %.1fs",
            $removed,
            $missing,
            $errored,
            $this->cliHelper->formatBytes($freedBytes),
            number_format($freedBytes),
            $deltaSign,
            $this->cliHelper->formatBytes($deltaAbs),
            number_format($deltaAbs),
            $endTime - $startTime,
        ));

        return self::SUCCESS;
    }

    private function reportMissingAvif(OutputInterface $output, string $path): void
    {
        $output->writeln(sprintf('<comment>Skipped missing AVIF: %s</comment>', basename($path)));
    }
}
