<?php

declare(strict_types=1);

namespace App\Video\Domain;

final readonly class ProcessResult
{
    /** @param list<string> $output All output lines from the process */
    public function __construct(
        public int $exitCode,
        public array $output = [],
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->exitCode === 0;
    }
}
