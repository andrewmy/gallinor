<?php

declare(strict_types=1);

namespace App\Images\Ui\Cli\Parallel;

use App\Images\Domain\CalculationSkipReason;

final readonly class ParallelWorkerPayloadHandlingResult
{
    private function __construct(
        public bool $countAsSystemError,
        public bool $isStatus,
        public bool $isCompleted,
        public string|null $path = null,
        public int|null $quality = null,
        public float|null $score = null,
        public int|null $savedBytes = null,
        public string|null $outcome = null,
        public CalculationSkipReason|null $skipReason = null,
        public string|null $error = null,
        public int $savedDelta = 0,
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

    public static function status(string $path, int $quality, float $score, int $savedBytes): self
    {
        return new self(
            countAsSystemError: false,
            isStatus: true,
            isCompleted: false,
            path: $path,
            quality: $quality,
            score: $score,
            savedBytes: $savedBytes,
        );
    }

    public static function processed(string $path, int $savedDelta): self
    {
        return new self(
            countAsSystemError: false,
            isStatus: false,
            isCompleted: true,
            path: $path,
            outcome: 'processed',
            savedDelta: $savedDelta,
        );
    }

    public static function skipped(string $path, CalculationSkipReason $skipReason): self
    {
        return new self(
            countAsSystemError: false,
            isStatus: false,
            isCompleted: true,
            path: $path,
            outcome: 'skipped',
            skipReason: $skipReason,
        );
    }

    public static function errored(string $path, string $error): self
    {
        return new self(
            countAsSystemError: false,
            isStatus: false,
            isCompleted: true,
            path: $path,
            outcome: 'error',
            error: $error,
        );
    }
}
