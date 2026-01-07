<?php

declare(strict_types=1);

namespace App\Ui\Cli;

use App\Domain\Platform;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

use function microtime;
use function sprintf;

use const PHP_EOL;

#[AsCommand(name: 'images', description: 'Re-encode JPEGs to optimal AVIFs, XZ the ARWs')]
final class Images extends Command
{
    private Platform $platform;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly CliHelper $cliHelper,
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
        try {
            $this->platform = new Platform();
        } catch (Throwable $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
