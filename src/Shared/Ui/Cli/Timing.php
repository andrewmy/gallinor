<?php

declare(strict_types=1);

namespace App\Shared\Ui\Cli;

use Symfony\Component\Console\Output\OutputInterface;

use function implode;
use function sprintf;

final readonly class Timing
{
    public function __construct(
        public float $total,
        public float|null $init = null,
        public float|null $gather = null,
        public float|null $process = null,
        public float|null $qc = null,
        public float|null $archiving = null,
        public float|null $rename = null,
        public float|null $remove = null,
    ) {
    }

    /** @return list<string> */
    private function formatLines(): array
    {
        $lines = ['Timing:'];

        if ($this->init !== null) {
            $lines[] = sprintf('  Init: %.3fs', $this->init);
        }

        if ($this->gather !== null) {
            $lines[] = sprintf('  Gather: %.3fs', $this->gather);
        }

        if ($this->process !== null) {
            $lines[] = sprintf('  Process: %.3fs', $this->process);
        }

        if ($this->qc !== null) {
            $lines[] = sprintf('  QC: %.3fs', $this->qc);
        }

        if ($this->archiving !== null) {
            $lines[] = sprintf('  Archiving: %.3fs', $this->archiving);
        }

        if ($this->rename !== null) {
            $lines[] = sprintf('  Rename: %.3fs', $this->rename);
        }

        if ($this->remove !== null) {
            $lines[] = sprintf('  Remove: %.3fs', $this->remove);
        }

        $lines[] = sprintf('  Total: %.3fs', $this->total);

        return $lines;
    }

    public function format(): string
    {
        return implode("\n", $this->formatLines());
    }

    public function print(OutputInterface $output): void
    {
        $output->writeln('<info>' . $this->format() . '</info>');
    }
}
