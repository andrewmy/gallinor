<?php

declare(strict_types=1);

namespace App\Images\Ui\Cli\Parallel;

final readonly class JobRetryPolicy
{
    public function __construct(private int $maxAttempts = 2)
    {
    }

    public function nextAttemptNumber(int $attempt): int|null
    {
        $nextAttempt = $attempt + 1;
        if ($nextAttempt >= $this->maxAttempts) {
            return null;
        }

        return $nextAttempt;
    }
}
