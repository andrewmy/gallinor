<?php

declare(strict_types=1);

namespace App\Images\Ui\Cli;

use App\Images\Domain\AvifMigrationPlanner;
use App\Shared\Domain\FilesystemScanner;
use App\Shared\Ui\Cli\CliHelper;
use App\Shared\Ui\Cli\Timing;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

use function basename;
use function count;
use function microtime;
use function sprintf;
use function unlink;

use const PHP_EOL;

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
        $output->writeln(sprintf('<info>Dry run: %s</info>%s', $dryRun ? 'Yes' : 'No', PHP_EOL));
        $output->writeln(sprintf('<info>Init time: %s</info>%s', $this->timing->formatInit(), PHP_EOL));

        $startTime = microtime(true);

        $plan       = (new AvifMigrationPlanner($this->scanner))->plan($directories);
        $candidates = $plan->alreadyMigratedAvifs;

        $output->writeln(sprintf('<info>Found %d AVIFs removable</info>', count($candidates)));

        if ($dryRun) {
            foreach ($candidates as $path) {
                $output->writeln(sprintf('  Will remove: %s', $this->cliHelper->link($path)));
            }

            return self::SUCCESS;
        }

        $removed = 0;
        $errored = 0;

        foreach ($candidates as $path) {
            try {
                unlink($path);
                $removed++;
            } catch (Throwable $exception) {
                $errored++;
                $output->writeln(sprintf('<error>%s: %s</error>', basename($path), $exception->getMessage()));
            }
        }

        $endTime = microtime(true);

        $output->writeln(sprintf("\nSummary:\n  Removed: %d\n  Errored: %d\n  Time: %.1fs", $removed, $errored, $endTime - $startTime));

        return self::SUCCESS;
    }
}
