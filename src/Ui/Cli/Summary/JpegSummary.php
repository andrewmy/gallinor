<?php

declare(strict_types=1);

namespace App\Ui\Cli\Summary;

use App\Ui\Cli\CliHelper;
use Symfony\Component\Console\Output\OutputInterface;

use function implode;
use function sprintf;

final readonly class JpegSummary
{
    public function __construct(
        public int $found,
        public int|null $processed = null,
        public int|null $skipped = null,
        public int|null $removed = null,
        public int|null $errored = null,
        public int|null $sizeBefore = null,
        public int|null $sizeAfter = null,
        public int|null $removedSize = null,
        public int|null $replacementSize = null,
    ) {
    }

    /** @return list<string> */
    private function formatLines(CliHelper $helper): array
    {
        $lines   = ['JPEG Summary:'];
        $lines[] = sprintf('  Found: %d', $this->found);

        if ($this->processed !== null) {
            $lines[] = sprintf('  Processed: %d', $this->processed);
        }

        if ($this->skipped !== null) {
            $lines[] = sprintf('  Skipped: %d', $this->skipped);
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
            $savings = $this->removedSize - ($this->replacementSize ?? 0);
            $lines[] = sprintf('  Savings: %s', $helper->formatBytes($savings));
        }

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
