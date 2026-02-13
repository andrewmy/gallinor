<?php

declare(strict_types=1);

namespace App\Images\Ui\Cli\Parallel;

use function max;
use function min;

final class AdaptiveConcurrencyState
{
    public readonly int $maxWorkers;
    public readonly int $initialRequestedWorkers;

    public bool $enabled;
    public float|null $windowStartedAt = null;
    public int $windowCompleted        = 0;
    public float|null $lastThroughput  = null;

    public function __construct(int $maxWorkers, int|null $startWorkers = null)
    {
        $this->maxWorkers = max(1, $maxWorkers);

        $this->initialRequestedWorkers = max(1, min(
            $this->maxWorkers,
            $startWorkers ?? $this->maxWorkers,
        ));

        $this->enabled = $startWorkers !== null
            && $this->initialRequestedWorkers < $this->maxWorkers;
    }
}
