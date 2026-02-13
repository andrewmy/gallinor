<?php

declare(strict_types=1);

namespace App\Images\Ui\Cli\Parallel;

use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;

use function implode;
use function ksort;
use function md5;
use function microtime;
use function preg_match;
use function sprintf;

final class ParallelConsoleTelemetry
{
    private const float MIN_PANEL_INTERVAL_SECONDS = 0.20;

    /** @var array<string, string> */
    private array $workerStates = [];
    private ConsoleSectionOutput|null $statusSection;
    private string|null $lastTrace          = null;
    private float $lastPanelRenderAt        = 0.0;
    private string|null $lastPanelSignature = null;

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
            // Avoid panel redraw storms from verbose trace-only events.
            // The panel is refreshed on worker state changes and finish().

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

        if ($state === 'spawned') {
            $this->dropOneExitedWorker($workerId);
        }

        ksort($this->workerStates);
        $this->renderPanel();
    }

    public function finish(): void
    {
        $statusSection = $this->statusSection;
        if ($statusSection === null || $this->output->getVerbosity() < OutputInterface::VERBOSITY_VERY_VERBOSE) {
            return;
        }

        $this->renderPanel(force: true);
        $statusSection->clear();
    }

    public function printInlineError(string $message): void
    {
        if ($this->statusSection !== null) {
            $this->statusSection->clear();
        }

        $this->progressBar->clear();
        $this->output->writeln(sprintf('<error>%s</error>', $message));
        if ($this->isPanelMode()) {
            $this->renderPanel(force: true);
        } else {
            $this->progressBar->display();
        }
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

    private function dropOneExitedWorker(string $spawnedWorkerId): void
    {
        foreach ($this->workerStates as $workerId => $summary) {
            if ($workerId === $spawnedWorkerId) {
                continue;
            }

            if ($summary !== 'exited') {
                continue;
            }

            unset($this->workerStates[$workerId]);
            break;
        }
    }

    private function renderPanel(bool $force = false): void
    {
        $statusSection = $this->statusSection;
        if ($statusSection === null) {
            return;
        }

        if (! $force && $this->workerStates === []) {
            return;
        }

        $lines = ['<fg=cyan>Workers</>'];
        foreach ($this->workerStates as $id => $summary) {
            $lines[] = sprintf('  <fg=gray>%s</> %s', $this->workerLabel($id), $summary);
        }

        if ($this->lastTrace !== null && $this->lastTrace !== '') {
            $lines[] = sprintf('<fg=cyan>%s</>', $this->lastTrace);
        }

        $signature = md5(implode("\n", $lines));
        $now       = microtime(true);
        if (! $force) {
            if ($signature === $this->lastPanelSignature) {
                return;
            }

            if ($now - $this->lastPanelRenderAt < self::MIN_PANEL_INTERVAL_SECONDS) {
                return;
            }
        }

        $this->progressBar->clear();
        $statusSection->overwrite($lines);
        $this->progressBar->display();
        $this->lastPanelRenderAt  = $now;
        $this->lastPanelSignature = $signature;
    }
}
