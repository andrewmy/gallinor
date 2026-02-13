<?php

declare(strict_types=1);

namespace App\Images\Ui\Cli\Parallel;

use function min;

final class ParallelExecutionPlanResolver
{
    public static function validationError(
        int|null $concurrency,
        int|null $adaptiveConcurrency,
        int $workerMaxJobs,
        int $jobTimeout,
    ): string|null {
        if ($concurrency !== null && $concurrency <= 0) {
            return 'Invalid --concurrency: must be a positive integer.';
        }

        if ($adaptiveConcurrency !== null && $adaptiveConcurrency <= 0) {
            return 'Invalid --adaptive-concurrency: must be a positive integer.';
        }

        if ($concurrency !== null && $adaptiveConcurrency !== null) {
            return '--concurrency and --adaptive-concurrency are mutually exclusive.';
        }

        if ($workerMaxJobs <= 0) {
            return 'Invalid --worker-max-jobs: must be a positive integer.';
        }

        if ($jobTimeout < 0) {
            return 'Invalid --job-timeout: must be zero or a positive integer.';
        }

        return null;
    }

    public static function resolve(
        int $nCores,
        int|null $concurrency,
        int|null $adaptiveConcurrency,
    ): ParallelExecutionPlan {
        $safeConcurrency = ParallelConcurrency::defaultFromCores($nCores);

        if ($adaptiveConcurrency !== null) {
            return new ParallelExecutionPlan(
                workers: $adaptiveConcurrency,
                adaptiveStartWorkers: min($safeConcurrency, $adaptiveConcurrency),
                strategy: ParallelExecutionPlan::STRATEGY_ADAPTIVE,
            );
        }

        if ($concurrency !== null) {
            return new ParallelExecutionPlan(
                workers: $concurrency,
                adaptiveStartWorkers: null,
                strategy: ParallelExecutionPlan::STRATEGY_FIXED,
            );
        }

        return new ParallelExecutionPlan(
            workers: $safeConcurrency,
            adaptiveStartWorkers: null,
            strategy: ParallelExecutionPlan::STRATEGY_FIXED_SAFE,
        );
    }
}
