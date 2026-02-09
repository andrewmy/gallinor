<?php

declare(strict_types=1);

namespace App\Tests\Unit\Images\Ui\Cli\Parallel;

use App\Images\Ui\Cli\Parallel\ParallelConcurrency;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ParallelConcurrencyTest extends TestCase
{
    /** @return iterable<string, array{cores: int, expected: int}> */
    public static function defaultConcurrencyProvider(): iterable
    {
        yield 'single-core fallback' => ['cores' => 1, 'expected' => 1];
        yield 'two cores' => ['cores' => 2, 'expected' => 1];
        yield 'four cores' => ['cores' => 4, 'expected' => 2];
        yield 'eight cores' => ['cores' => 8, 'expected' => 3];
        yield 'ten cores' => ['cores' => 10, 'expected' => 4];
        yield 'twelve cores' => ['cores' => 12, 'expected' => 4];
        yield 'sixteen cores' => ['cores' => 16, 'expected' => 5];
        yield 'thirty two cores' => ['cores' => 32, 'expected' => 7];
    }

    #[DataProvider('defaultConcurrencyProvider')]
    public function test_default_from_cores_uses_sublinear_formula(int $cores, int $expected): void
    {
        self::assertSame($expected, ParallelConcurrency::defaultFromCores($cores));
    }
}
