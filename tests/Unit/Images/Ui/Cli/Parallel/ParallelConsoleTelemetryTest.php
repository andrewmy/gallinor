<?php

declare(strict_types=1);

namespace App\Tests\Unit\Images\Ui\Cli\Parallel;

use App\Images\Ui\Cli\Parallel\ParallelConsoleTelemetry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;

use function fclose;
use function fseek;
use function ftell;
use function rewind;
use function stream_get_contents;

final class ParallelConsoleTelemetryTest extends TestCase
{
    public function test_trace_only_event_does_not_render_panel_until_worker_update(): void
    {
        $output = new TestConsoleOutput();
        $stream = $output->stream();

        $progressBar = new ProgressBar($output, 10);
        $progressBar->setFormat(' %current%/%max% %status%');
        $progressBar->setMessage('Starting...', 'status');

        $telemetry = new ParallelConsoleTelemetry($output, $progressBar);
        $telemetry->trace('[parallel] debug trace', OutputInterface::VERBOSITY_DEBUG);

        self::assertStringNotContainsString('Workers', self::streamContent($stream));

        $telemetry->onWorkerUpdate('run-123-worker-1', 'spawned');

        $rendered = self::streamContent($stream);
        self::assertStringContainsString('Workers', $rendered);
        self::assertStringContainsString('w1', $rendered);
        self::assertStringContainsString('[parallel] debug trace', $rendered);

        fclose($stream);
    }

    /** @param resource $stream */
    private static function streamContent($stream): string
    {
        $position = ftell($stream);
        rewind($stream);
        $content = (string) stream_get_contents($stream);
        fseek($stream, $position);

        return $content;
    }
}
