<?php

declare(strict_types=1);

namespace App\Tests\Unit\Images\Ui\Cli\Parallel;

use App\Images\Ui\Cli\Parallel\JobRetryPolicy;
use PHPUnit\Framework\TestCase;

final class JobRetryPolicyTest extends TestCase
{
    public function test_first_failure_requeues_job_once(): void
    {
        $policy = new JobRetryPolicy();
        $retry  = $policy->nextAttemptNumber(0);

        self::assertSame(1, $retry);
    }

    public function test_second_failure_does_not_requeue(): void
    {
        $policy = new JobRetryPolicy();

        self::assertNull($policy->nextAttemptNumber(1));
    }
}
