<?php

declare(strict_types=1);

namespace App\Tests\Unit\Images\Ui\Cli\Parallel;

use App\Images\Ui\Cli\Parallel\ParallelExecutionPlan;
use App\Images\Ui\Cli\Parallel\ParallelExecutionPlanResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ParallelExecutionPlanResolverTest extends TestCase
{
    /** @return iterable<string, array{concurrency: int|null, adaptiveConcurrency: int|null, workerMaxJobs: int, jobTimeout: int, expected: string}> */
    public static function validationErrorProvider(): iterable
    {
        yield 'invalid concurrency' => [
            'concurrency'         => 0,
            'adaptiveConcurrency' => null,
            'workerMaxJobs'       => 10,
            'jobTimeout'          => 60,
            'expected'            => 'Invalid --concurrency: must be a positive integer.',
        ];

        yield 'invalid adaptive concurrency' => [
            'concurrency'         => null,
            'adaptiveConcurrency' => -1,
            'workerMaxJobs'       => 10,
            'jobTimeout'          => 60,
            'expected'            => 'Invalid --adaptive-concurrency: must be a positive integer.',
        ];

        yield 'mutually exclusive options' => [
            'concurrency'         => 4,
            'adaptiveConcurrency' => 6,
            'workerMaxJobs'       => 10,
            'jobTimeout'          => 60,
            'expected'            => '--concurrency and --adaptive-concurrency are mutually exclusive.',
        ];

        yield 'invalid worker max jobs' => [
            'concurrency'         => null,
            'adaptiveConcurrency' => null,
            'workerMaxJobs'       => 0,
            'jobTimeout'          => 60,
            'expected'            => 'Invalid --worker-max-jobs: must be a positive integer.',
        ];

        yield 'invalid job timeout' => [
            'concurrency'         => null,
            'adaptiveConcurrency' => null,
            'workerMaxJobs'       => 10,
            'jobTimeout'          => -1,
            'expected'            => 'Invalid --job-timeout: must be zero or a positive integer.',
        ];
    }

    #[DataProvider('validationErrorProvider')]
    public function test_validation_errors_are_reported(
        int|null $concurrency,
        int|null $adaptiveConcurrency,
        int $workerMaxJobs,
        int $jobTimeout,
        string $expected,
    ): void {
        self::assertSame(
            $expected,
            ParallelExecutionPlanResolver::validationError(
                concurrency: $concurrency,
                adaptiveConcurrency: $adaptiveConcurrency,
                workerMaxJobs: $workerMaxJobs,
                jobTimeout: $jobTimeout,
            ),
        );
    }

    public function test_validation_error_is_null_for_valid_input(): void
    {
        self::assertNull(ParallelExecutionPlanResolver::validationError(
            concurrency: null,
            adaptiveConcurrency: 10,
            workerMaxJobs: 50,
            jobTimeout: 3600,
        ));
    }

    public function test_resolve_uses_safe_fixed_workers_when_no_overrides_are_provided(): void
    {
        $plan = ParallelExecutionPlanResolver::resolve(
            nCores: 12,
            concurrency: null,
            adaptiveConcurrency: null,
        );

        self::assertSame(4, $plan->workers);
        self::assertNull($plan->adaptiveStartWorkers);
        self::assertSame(ParallelExecutionPlan::STRATEGY_FIXED_SAFE, $plan->strategy);
    }

    public function test_resolve_uses_fixed_workers_when_concurrency_is_provided(): void
    {
        $plan = ParallelExecutionPlanResolver::resolve(
            nCores: 12,
            concurrency: 6,
            adaptiveConcurrency: null,
        );

        self::assertSame(6, $plan->workers);
        self::assertNull($plan->adaptiveStartWorkers);
        self::assertSame(ParallelExecutionPlan::STRATEGY_FIXED, $plan->strategy);
    }

    public function test_resolve_uses_adaptive_starting_from_safe_workers(): void
    {
        $plan = ParallelExecutionPlanResolver::resolve(
            nCores: 12,
            concurrency: null,
            adaptiveConcurrency: 10,
        );

        self::assertSame(10, $plan->workers);
        self::assertSame(4, $plan->adaptiveStartWorkers);
        self::assertSame(ParallelExecutionPlan::STRATEGY_ADAPTIVE, $plan->strategy);
    }

    public function test_resolve_caps_adaptive_start_to_max_workers_when_max_is_below_safe(): void
    {
        $plan = ParallelExecutionPlanResolver::resolve(
            nCores: 12,
            concurrency: null,
            adaptiveConcurrency: 2,
        );

        self::assertSame(2, $plan->workers);
        self::assertSame(2, $plan->adaptiveStartWorkers);
        self::assertSame(ParallelExecutionPlan::STRATEGY_ADAPTIVE, $plan->strategy);
    }

    public function test_enabled_message_formats_adaptive_mode(): void
    {
        $plan = new ParallelExecutionPlan(
            workers: 10,
            adaptiveStartWorkers: 4,
            strategy: ParallelExecutionPlan::STRATEGY_ADAPTIVE,
        );

        self::assertSame(
            'Parallel JPEG mode: enabled (adaptive start-workers=4, max-workers=10, worker-max-jobs=50, job-timeout=3600s)',
            $plan->enabledMessage('JPEG', 50, 3600),
        );
    }
}
