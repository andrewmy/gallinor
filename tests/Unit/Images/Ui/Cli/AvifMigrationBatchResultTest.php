<?php

declare(strict_types=1);

namespace App\Tests\Unit\Images\Ui\Cli;

use App\Images\Ui\Cli\AvifMigrationBatchResult;
use PHPUnit\Framework\TestCase;

final class AvifMigrationBatchResultTest extends TestCase
{
    public function test_counts_and_total_delta_bytes(): void
    {
        $result = new AvifMigrationBatchResult(
            processed: [
                '/tmp/a.avif' => -1200,
                '/tmp/b.avif' => 300,
            ],
            skipped: ['/tmp/c.avif' => 'replacement not smaller than original'],
            errored: ['/tmp/d.avif' => 'Something failed.'],
        );

        self::assertSame(2, $result->processedCount());
        self::assertSame(1, $result->skippedCount());
        self::assertSame(1, $result->erroredCount());
        self::assertSame(-900, $result->totalDeltaBytes());
    }
}
