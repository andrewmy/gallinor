<?php

declare(strict_types=1);

namespace App\Ui\Cli;

use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;

use function sprintf;

final class CliHelper
{
    public function link(string $path, string|null $label = null): string
    {
        $label ??= $path;

        return sprintf("\e]8;;file://%s\e\\%s\e]8;;\e\\", $path, $label);
    }

    public function createProgressBar(OutputInterface $output, int $max, string $label = 'Processing'): ProgressBar
    {
        $progressBar = new ProgressBar($output, $max);
        $progressBar->setFormat(
            sprintf(" %s: %%current%%/%%max%% [%%bar%%] %%elapsed:6s%% / %%remaining:6s%% | %%status%%\n", $label),
        );
        $progressBar->setMessage('Starting...', 'status');
        $progressBar->setBarWidth(30);

        return $progressBar;
    }
}
