<?php

declare(strict_types=1);

namespace App\Images\Ui\Cli\Parallel;

final readonly class ParallelWorkerPoolPayloadResult
{
    private function __construct(
        public bool $countAsSystemError,
        public bool $isStatus,
        public bool $isCompleted,
    ) {
    }

    public static function systemError(): self
    {
        return new self(
            countAsSystemError: true,
            isStatus: false,
            isCompleted: false,
        );
    }

    public static function ignored(): self
    {
        return new self(
            countAsSystemError: false,
            isStatus: false,
            isCompleted: false,
        );
    }

    public static function status(): self
    {
        return new self(
            countAsSystemError: false,
            isStatus: true,
            isCompleted: false,
        );
    }

    public static function completed(): self
    {
        return new self(
            countAsSystemError: false,
            isStatus: false,
            isCompleted: true,
        );
    }
}
