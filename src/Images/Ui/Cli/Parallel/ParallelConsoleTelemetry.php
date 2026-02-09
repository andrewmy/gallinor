<?php

declare(strict_types=1);

namespace App\Images\Ui\Cli\Parallel;

use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;

use function ksort;
use function preg_match;
use function sprintf;

final class ParallelConsoleTelemetry
{
    /** @var array<string, string> */
    private array $workerStates = [];
    private ConsoleSectionOutput|null $statusSection;
    private string|null $lastTrace = null;

    public function __construct(
        private readonly OutputInterface $output,
        private readonly ProgressBar $progressBar,
    ) {
        $this->statusSection = $output instanceof ConsoleOutputInterface && $output->isDecorated() ? $output->section() : null;
    }

    public function trace(string $message, int $verbosity): void
    {
        if ($this->output->getVerbosity() < $verbosity) {
            return;
        }

        if ($this->isPanelMode()) {
            $this->lastTrace = $message;
            $this->renderPanel();

            return;
        }

        $this->progressBar->clear();
        $this->output->writeln(sprintf('<fg=cyan>%s</>', $message));
        $this->progressBar->display();
    }

    public function onWorkerUpdate(string $workerId, string $state): void
    {
        if (! $this->isPanelMode()) {
            return;
        }

        $this->workerStates[$workerId] = $state;
        ksort($this->workerStates);
        $this->renderPanel();
    }

    public function finish(): void
    {
        $statusSection = $this->statusSection;
        if ($statusSection === null || $this->output->getVerbosity() < OutputInterface::VERBOSITY_VERY_VERBOSE) {
            return;
        }

        $statusSection->clear();
    }

    private function workerLabel(string $workerId): string
    {
        if (preg_match('/worker-(\d+)$/', $workerId, $matches) === 1) {
            return sprintf('w%s', $matches[1]);
        }

        return $workerId;
    }

    private function isPanelMode(): bool
    {
        return $this->statusSection !== null && $this->output->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE;
    }

    private function renderPanel(): void
    {
        $statusSection = $this->statusSection;
        if ($statusSection === null) {
            return;
        }

        $lines = ['<fg=cyan>Workers</>'];
        foreach ($this->workerStates as $id => $summary) {
            $lines[] = sprintf('  <fg=gray>%s</> %s', $this->workerLabel($id), $summary);
        }

        if ($this->lastTrace !== null && $this->lastTrace !== '') {
            $lines[] = sprintf('<fg=cyan>%s</>', $this->lastTrace);
        }

        $statusSection->overwrite($lines);
    }
}
