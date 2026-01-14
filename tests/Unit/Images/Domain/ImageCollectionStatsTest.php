<?php

declare(strict_types=1);

namespace App\Tests\Unit\Images\Domain;

use App\Images\Domain\ImageCollectionStats;
use PHPUnit\Framework\TestCase;

final class ImageCollectionStatsTest extends TestCase
{
    public function test_default_values_are_all_zero(): void
    {
        $stats = new ImageCollectionStats();

        self::assertSame(0, $stats->jpegsFound);
        self::assertSame(0, $stats->jpegsSkipped);
        self::assertSame(0, $stats->arwsFound);
    }

    public function test_can_be_initialized_with_values(): void
    {
        $stats = new ImageCollectionStats(
            jpegsFound: 10,
            jpegsSkipped: 3,
            arwsFound: 5,
        );

        self::assertSame(10, $stats->jpegsFound);
        self::assertSame(3, $stats->jpegsSkipped);
        self::assertSame(5, $stats->arwsFound);
    }

    public function test_add_jpegs_found_returns_new_instance_with_incremented_count(): void
    {
        $stats = new ImageCollectionStats(jpegsFound: 5);

        $newStats = $stats->addJpegsFound();

        self::assertSame(5, $stats->jpegsFound, 'Original instance should be unchanged');
        self::assertSame(6, $newStats->jpegsFound);
    }

    public function test_add_jpegs_found_can_be_called_multiple_times(): void
    {
        $stats = new ImageCollectionStats();

        $stats1 = $stats->addJpegsFound();
        $stats2 = $stats1->addJpegsFound();
        $stats3 = $stats2->addJpegsFound();

        self::assertSame(0, $stats->jpegsFound);
        self::assertSame(1, $stats1->jpegsFound);
        self::assertSame(2, $stats2->jpegsFound);
        self::assertSame(3, $stats3->jpegsFound);
    }

    public function test_add_jpegs_skipped_returns_new_instance_with_incremented_count(): void
    {
        $stats = new ImageCollectionStats(jpegsSkipped: 2);

        $newStats = $stats->addJpegsSkipped();

        self::assertSame(2, $stats->jpegsSkipped, 'Original instance should be unchanged');
        self::assertSame(3, $newStats->jpegsSkipped);
    }

    public function test_add_arws_found_returns_new_instance_with_incremented_count(): void
    {
        $stats = new ImageCollectionStats(arwsFound: 7);

        $newStats = $stats->addArwsFound();

        self::assertSame(7, $stats->arwsFound, 'Original instance should be unchanged');
        self::assertSame(8, $newStats->arwsFound);
    }

    public function test_all_adders_preserve_other_counters(): void
    {
        $stats = new ImageCollectionStats(
            jpegsFound: 10,
            jpegsSkipped: 3,
            arwsFound: 5,
        );

        $statsAfterJpegsFound   = $stats->addJpegsFound();
        $statsAfterJpegsSkipped = $statsAfterJpegsFound->addJpegsSkipped();
        $statsAfterArwsFound    = $statsAfterJpegsSkipped->addArwsFound();

        self::assertSame(11, $statsAfterArwsFound->jpegsFound);
        self::assertSame(4, $statsAfterArwsFound->jpegsSkipped);
        self::assertSame(6, $statsAfterArwsFound->arwsFound);
    }
}
