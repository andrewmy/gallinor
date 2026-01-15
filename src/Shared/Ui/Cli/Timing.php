<?php

declare(strict_types=1);

namespace App\Shared\Ui\Cli;

use Symfony\Component\Console\Output\OutputInterface;

use function implode;
use function microtime;
use function sprintf;

final readonly class Timing
{
    public function __construct(
        public float $total = 0,
        public float|null $init = null,
        public float|null $gather = null,
        public float|null $process = null,
        public float|null $qc = null,
        public float|null $archiving = null,
        public float|null $rename = null,
        public float|null $remove = null,
        private float|null $startTime = null,
    ) {
    }

    public static function start(float $startTime): self
    {
        return new self(startTime: $startTime);
    }

    public function recordInit(): self
    {
        return new self(
            total: 0,
            init: microtime(true) - $this->startTime,
        );
    }

    public function initSeconds(): float
    {
        return $this->init ?? 0.0;
    }

    public function formatInit(): string
    {
        return self::formatSecs($this->initSeconds());
    }

    public function withTotal(float $total): self
    {
        return $this->with('total', $total);
    }

    public function withGather(float $seconds): self
    {
        return $this->with('gather', $seconds);
    }

    public function withProcess(float $seconds): self
    {
        return $this->with('process', $seconds);
    }

    public function withQc(float $seconds): self
    {
        return $this->with('qc', $seconds);
    }

    public function withArchiving(float $seconds): self
    {
        return $this->with('archiving', $seconds);
    }

    public function withRename(float $seconds): self
    {
        return $this->with('rename', $seconds);
    }

    public function withRemove(float $seconds): self
    {
        return $this->with('remove', $seconds);
    }

    public function print(OutputInterface $output): void
    {
        $output->writeln('<info>' . implode("\n", $this->formatLines()) . '</info>');
    }

    /** @return list<string> */
    private function formatLines(): array
    {
        $lines = ['Timing:'];

        if ($this->init !== null) {
            $lines[] = sprintf('  Init: %s', self::formatSecs($this->init));
        }

        if ($this->gather !== null) {
            $lines[] = sprintf('  Gather: %s', self::formatSecs($this->gather));
        }

        if ($this->process !== null) {
            $lines[] = sprintf('  Process: %s', self::formatSecs($this->process));
        }

        if ($this->qc !== null) {
            $lines[] = sprintf('  QC: %s', self::formatSecs($this->qc));
        }

        if ($this->archiving !== null) {
            $lines[] = sprintf('  Archiving: %s', self::formatSecs($this->archiving));
        }

        if ($this->rename !== null) {
            $lines[] = sprintf('  Rename: %s', self::formatSecs($this->rename));
        }

        if ($this->remove !== null) {
            $lines[] = sprintf('  Remove: %s', self::formatSecs($this->remove));
        }

        $lines[] = sprintf('  Total: %s', self::formatSecs($this->total));

        return $lines;
    }

    private function with(string $property, float $value): self
    {
        return new self(
            total: $property === 'total' ? $value : $this->total,
            init: $this->init,
            gather: $property === 'gather' ? $value : $this->gather,
            process: $property === 'process' ? $value : $this->process,
            qc: $property === 'qc' ? $value : $this->qc,
            archiving: $property === 'archiving' ? $value : $this->archiving,
            rename: $property === 'rename' ? $value : $this->rename,
            remove: $property === 'remove' ? $value : $this->remove,
            startTime: $this->startTime,
        );
    }

    private static function formatSecs(float $seconds): string
    {
        return sprintf('%.3fs', $seconds);
    }
}
