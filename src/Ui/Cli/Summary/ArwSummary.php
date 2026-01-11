<?php

declare(strict_types=1);

namespace App\Ui\Cli\Summary;

use App\Ui\Cli\CliHelper;
use Symfony\Component\Console\Output\OutputInterface;

use function implode;
use function sprintf;

final readonly class ArwSummary
{
    public function __construct(
        public int $found,
        public int|null $archived = null,
        public int|null $skipped = null,
        public int|null $notArchived = null,
        public int|null $removed = null,
        public int|null $errored = null,
        public int|null $sizeBefore = null,
        public int|null $sizeAfter = null,
        public int|null $removedSize = null,
    ) {
    }

    public function print(OutputInterface $output, CliHelper $helper): void
    {
        $lines   = ['ARW Summary:'];
        $lines[] = sprintf('  Found: %d', $this->found);

        if ($this->archived !== null) {
            $lines[] = sprintf('  Archived: %d', $this->archived);
        }

        if ($this->skipped !== null) {
            $lines[] = sprintf('  Skipped: %d', $this->skipped);
        }

        if ($this->notArchived !== null) {
            $lines[] = sprintf('  Not archived: %d', $this->notArchived);
        }

        if ($this->removed !== null) {
            $lines[] = sprintf('  Removed: %d', $this->removed);
        }

        if ($this->errored !== null) {
            $lines[] = sprintf('  Errored: %d', $this->errored);
        }

        if ($this->sizeBefore !== null && $this->sizeAfter !== null) {
            $lines[] = sprintf('  Size before: %s', $helper->formatBytes($this->sizeBefore));
            $lines[] = sprintf('  Size after: %s', $helper->formatBytes($this->sizeAfter));
            $lines[] = sprintf('  Savings: %s', $helper->formatBytes($this->sizeBefore - $this->sizeAfter));
        }

        if ($this->removedSize !== null) {
            $lines[] = sprintf('  Removed size: %s', $helper->formatBytes($this->removedSize));
            $lines[] = sprintf('  Savings: %s', $helper->formatBytes($this->removedSize));
        }

        $output->writeln(implode("\n", $lines));
    }
}
