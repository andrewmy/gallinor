<?php

declare(strict_types=1);

namespace App\Images\Ui\Cli;

use function count;

final class AvifMigrationBatchResult
{
    /**
     * @param array<string, int>    $processed Map of AVIF path => delta bytes (HEIC size - AVIF size)
     * @param array<string, string> $skipped   Map of AVIF path => skip reason
     * @param array<string, string> $errored   Map of AVIF path => error message
     */
    public function __construct(
        public array $processed = [],
        public array $skipped = [],
        public array $errored = [],
    ) {
    }

    public function processedCount(): int
    {
        return count($this->processed);
    }

    public function skippedCount(): int
    {
        return count($this->skipped);
    }

    public function erroredCount(): int
    {
        return count($this->errored);
    }

    public function totalDeltaBytes(): int
    {
        $total = 0;

        foreach ($this->processed as $delta) {
            $total += $delta;
        }

        return $total;
    }
}
