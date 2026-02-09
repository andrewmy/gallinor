<?php

declare(strict_types=1);

namespace App\Tests\Unit\Images\Ui\Cli\Parallel;

use App\Images\Domain\CalculationSkipReason;
use App\Images\Ui\Cli\ImageBatchResult;
use App\Images\Ui\Cli\Parallel\ParallelWorkerPayloadHandler;
use PHPUnit\Framework\TestCase;

final class ParallelWorkerPayloadHandlerTest extends TestCase
{
    private ParallelWorkerPayloadHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->handler = new ParallelWorkerPayloadHandler();
    }

    public function test_status_payload_is_decoded_without_mutating_result(): void
    {
        $result = new ImageBatchResult();

        $handling = $this->handler->handle([
            'type'      => 'status',
            'path'      => '/tmp/a.jpg',
            'quality'   => 72,
            'score'     => 91.4,
            'savedBytes' => 1234,
        ], $result);

        self::assertFalse($handling->countAsSystemError);
        self::assertTrue($handling->isStatus);
        self::assertFalse($handling->isCompleted);
        self::assertSame('/tmp/a.jpg', $handling->path);
        self::assertSame(72, $handling->quality);
        self::assertSame(91.4, $handling->score);
        self::assertSame(1234, $handling->savedBytes);
        self::assertSame([], $result->processed);
        self::assertSame([], $result->skipped);
        self::assertSame([], $result->errored);
    }

    public function test_invalid_status_payload_is_ignored(): void
    {
        $result = new ImageBatchResult();

        $handling = $this->handler->handle([
            'type'      => 'status',
            'path'      => '/tmp/a.jpg',
            'quality'   => 'bad',
            'score'     => 91.4,
            'savedBytes' => 1234,
        ], $result);

        self::assertFalse($handling->countAsSystemError);
        self::assertFalse($handling->isStatus);
        self::assertFalse($handling->isCompleted);
    }

    public function test_processed_result_updates_batch_and_returns_saved_delta(): void
    {
        $result = new ImageBatchResult();

        $handling = $this->handler->handle([
            'type'    => 'result',
            'path'    => '/tmp/a.jpg',
            'outcome' => 'processed',
            'result'  => [
                'format'        => 'heic',
                'optimizedSize' => 700,
                'originalSize'  => 1000,
                'qualityValue'  => 73,
                'qualityLabel'  => 'q',
                'qualityScore'  => 92.1,
                'qcTime'        => 0.8,
            ],
        ], $result);

        self::assertFalse($handling->countAsSystemError);
        self::assertTrue($handling->isCompleted);
        self::assertSame('processed', $handling->outcome);
        self::assertSame('/tmp/a.jpg', $handling->path);
        self::assertSame(300, $handling->savedDelta);
        self::assertArrayHasKey('/tmp/a.jpg', $result->processed);
    }

    public function test_skipped_result_updates_batch(): void
    {
        $result = new ImageBatchResult();

        $handling = $this->handler->handle([
            'type'       => 'result',
            'path'       => '/tmp/a.jpg',
            'outcome'    => 'skipped',
            'skipReason' => CalculationSkipReason::QualityNotAchieved->value,
        ], $result);

        self::assertFalse($handling->countAsSystemError);
        self::assertTrue($handling->isCompleted);
        self::assertSame('skipped', $handling->outcome);
        self::assertSame(CalculationSkipReason::QualityNotAchieved, $handling->skipReason);
        self::assertSame(CalculationSkipReason::QualityNotAchieved, $result->skipped['/tmp/a.jpg']);
    }

    public function test_error_result_defaults_message_when_missing(): void
    {
        $result = new ImageBatchResult();

        $handling = $this->handler->handle([
            'type'    => 'result',
            'path'    => '/tmp/a.jpg',
            'outcome' => 'error',
        ], $result);

        self::assertFalse($handling->countAsSystemError);
        self::assertTrue($handling->isCompleted);
        self::assertSame('error', $handling->outcome);
        self::assertSame('Unknown worker error.', $handling->error);
        self::assertSame('Unknown worker error.', $result->errored['/tmp/a.jpg']);
    }

    public function test_malformed_result_payload_is_system_error(): void
    {
        $result = new ImageBatchResult();

        $handling = $this->handler->handle([
            'type'    => 'result',
            'path'    => '/tmp/a.jpg',
            'outcome' => 'processed',
            'result'  => ['format' => 'heic'],
        ], $result);

        self::assertTrue($handling->countAsSystemError);
        self::assertFalse($handling->isStatus);
        self::assertFalse($handling->isCompleted);
        self::assertSame([], $result->processed);
    }
}
