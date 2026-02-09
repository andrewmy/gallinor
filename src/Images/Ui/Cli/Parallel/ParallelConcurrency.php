<?php

declare(strict_types=1);

namespace App\Images\Ui\Cli\Parallel;

use function intdiv;
use function max;
use function min;
use function round;
use function sqrt;

final class ParallelConcurrency
{
    public static function defaultFromCores(int $nCores): int
    {
        $nCores = max(1, $nCores);

        // Sublinear scaling to avoid oversubscription while still using modern CPUs.
        $heuristicWorkers = (int) round(1.15 * sqrt($nCores));
        $hardUpperBound   = intdiv($nCores + 1, 2);

        return max(1, min($heuristicWorkers, $hardUpperBound));
    }
}
