<?php

declare(strict_types=1);

namespace App\Images\Ui\Cli\Parallel;

use function intdiv;
use function max;
use function min;

final class ParallelConcurrency
{
    public static function defaultFromCores(int $nCores): int
    {
        $nCores = max(1, $nCores);

        return max(1, min(intdiv($nCores, 4), intdiv($nCores, 8) + 2));
    }
}
