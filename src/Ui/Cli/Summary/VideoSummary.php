<?php

declare(strict_types=1);

namespace App\Ui\Cli\Summary;

use App\Ui\Cli\CliHelper;
use Symfony\Component\Console\Output\OutputInterface;

use function implode;
use function sprintf;

final readonly class VideoSummary
{
    public function __construct(
        public int $sizeBefore,
        public int $sizeAfter,
        public int|null $found = null,
        public int|null $processed = null,
        public int|null $renamed = null,
        public int|null $skipped = null,
        public int|null $errored = null,
    ) {
    }

    /** @return list<string> */
    private function formatLines(CliHelper $helper): array
    {
        $lines = ['Video Summary:'];

        if ($this->found !== null) {
            $lines[] = sprintf('  Found: %d', $this->found);
        }

        if ($this->processed !== null) {
            $lines[] = sprintf('  Processed: %d', $this->processed);
        }

        if ($this->renamed !== null) {
            $lines[] = sprintf('  Renamed: %d', $this->renamed);
        }

        if ($this->skipped !== null) {
            $lines[] = sprintf('  Skipped: %d', $this->skipped);
        }

        if ($this->errored !== null) {
            $lines[] = sprintf('  Errored: %d', $this->errored);
        }

        $lines[] = sprintf('  Size before: %s', $helper->formatBytes($this->sizeBefore));
        $lines[] = sprintf('  Size after: %s', $helper->formatBytes($this->sizeAfter));
        $lines[] = sprintf('  Savings: %s', $helper->formatBytes($this->sizeBefore - $this->sizeAfter));

        return $lines;
    }

    public function format(CliHelper $helper): string
    {
        return implode("\n", $this->formatLines($helper));
    }

    public function print(OutputInterface $output, CliHelper $helper): void
    {
        $output->writeln($this->format($helper));
    }
}
