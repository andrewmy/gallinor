<?php

declare(strict_types=1);

namespace App\Images\Ui\Cli\Parallel;

use function sprintf;

final readonly class ParallelExecutionPlan
{
    public const string STRATEGY_FIXED      = 'fixed';
    public const string STRATEGY_FIXED_SAFE = 'fixed-safe';
    public const string STRATEGY_ADAPTIVE   = 'adaptive';

    public function __construct(
        public int $workers,
        public int|null $adaptiveStartWorkers,
        public string $strategy,
    ) {
    }

    public function enabledMessage(string $label, int $workerMaxJobs, int $jobTimeout): string
    {
        if ($this->strategy === self::STRATEGY_ADAPTIVE) {
            $startWorkers = $this->adaptiveStartWorkers ?? $this->workers;

            return sprintf(
                'Parallel %s mode: enabled (adaptive start-workers=%d, max-workers=%d, worker-max-jobs=%d, job-timeout=%ds)',
                $label,
                $startWorkers,
                $this->workers,
                $workerMaxJobs,
                $jobTimeout,
            );
        }

        if ($this->strategy === self::STRATEGY_FIXED_SAFE) {
            return sprintf(
                'Parallel %s mode: enabled (fixed safe workers=%d, worker-max-jobs=%d, job-timeout=%ds)',
                $label,
                $this->workers,
                $workerMaxJobs,
                $jobTimeout,
            );
        }

        return sprintf(
            'Parallel %s mode: enabled (fixed workers=%d, worker-max-jobs=%d, job-timeout=%ds)',
            $label,
            $this->workers,
            $workerMaxJobs,
            $jobTimeout,
        );
    }
}
