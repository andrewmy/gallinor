<?php

declare(strict_types=1);

namespace App\Images\Ui\Cli\Parallel;

use App\Images\Domain\CalculationSkipReason;
use App\Images\Domain\ImageFormat;
use App\Images\Domain\ImageProcessingResult;
use App\Images\Ui\Cli\ImageBatchResult;
use RuntimeException;

use function array_keys;
use function is_array;
use function is_float;
use function is_int;
use function is_string;

final class ParallelWorkerPayloadHandler
{
    /** @param array<mixed, mixed> $payload */
    public function handle(array $payload, ImageBatchResult $result): ParallelWorkerPayloadHandlingResult
    {
        $messageType = $payload['type'] ?? null;
        if (! is_string($messageType)) {
            return ParallelWorkerPayloadHandlingResult::systemError();
        }

        if ($messageType === 'status') {
            $path  = $payload['path'] ?? null;
            $score = $payload['score'] ?? null;
            $saved = $payload['savedBytes'] ?? null;
            $q     = $payload['quality'] ?? null;

            if (! is_string($path)) {
                return ParallelWorkerPayloadHandlingResult::ignored();
            }

            return ParallelWorkerPayloadHandlingResult::status(
                path: $path,
                quality: is_int($q) ? $q : null,
                score: is_float($score) || is_int($score) ? (float) $score : null,
                savedBytes: is_int($saved) ? $saved : null,
            );
        }

        if ($messageType !== 'result') {
            return ParallelWorkerPayloadHandlingResult::systemError();
        }

        $path    = $payload['path'] ?? null;
        $outcome = $payload['outcome'] ?? null;

        if (! is_string($path) || ! is_string($outcome)) {
            return ParallelWorkerPayloadHandlingResult::systemError();
        }

        if ($outcome === 'processed') {
            $resultPayload = $payload['result'] ?? null;
            if (! is_array($resultPayload)) {
                return ParallelWorkerPayloadHandlingResult::systemError();
            }

            $processedResult = $this->denormalizeProcessedResult($this->normalizeStringKeyedMap($resultPayload));
            if (! $processedResult instanceof ImageProcessingResult) {
                return ParallelWorkerPayloadHandlingResult::systemError();
            }

            $result->processed[$path] = $processedResult;

            return ParallelWorkerPayloadHandlingResult::processed(
                path: $path,
                savedDelta: $processedResult->originalSize - $processedResult->optimizedSize,
            );
        }

        if ($outcome === 'skipped') {
            $skipReasonRaw = $payload['skipReason'] ?? null;
            if (! is_string($skipReasonRaw)) {
                return ParallelWorkerPayloadHandlingResult::systemError();
            }

            $skipReason = CalculationSkipReason::tryFrom($skipReasonRaw);
            if (! $skipReason instanceof CalculationSkipReason) {
                return ParallelWorkerPayloadHandlingResult::systemError();
            }

            $result->skipped[$path] = $skipReason;

            return ParallelWorkerPayloadHandlingResult::skipped($path, $skipReason);
        }

        if ($outcome === 'error') {
            $error = $payload['error'] ?? null;
            if (! is_string($error)) {
                $error = 'Unknown worker error.';
            }

            $result->errored[$path] = $error;

            return ParallelWorkerPayloadHandlingResult::errored($path, $error);
        }

        return ParallelWorkerPayloadHandlingResult::systemError();
    }

    /** @param array<string, mixed> $payload */
    private function denormalizeProcessedResult(array $payload): ImageProcessingResult|null
    {
        $formatRaw     = $payload['format'] ?? null;
        $optimizedSize = $payload['optimizedSize'] ?? null;
        $originalSize  = $payload['originalSize'] ?? null;
        $qualityValue  = $payload['qualityValue'] ?? null;
        $qualityLabel  = $payload['qualityLabel'] ?? null;
        $qualityScore  = $payload['qualityScore'] ?? null;
        $qcTime        = $payload['qcTime'] ?? null;

        if (
            ! is_string($formatRaw)
            || ! is_int($optimizedSize)
            || ! is_int($originalSize)
            || ! is_int($qualityValue)
            || ! is_string($qualityLabel)
            || (! is_float($qualityScore) && ! is_int($qualityScore))
            || (! is_float($qcTime) && ! is_int($qcTime))
        ) {
            return null;
        }

        $format = ImageFormat::tryFrom($formatRaw);
        if (! $format instanceof ImageFormat) {
            return null;
        }

        return new ImageProcessingResult(
            format: $format,
            optimizedSize: $optimizedSize,
            originalSize: $originalSize,
            qualityValue: $qualityValue,
            qualityLabel: $qualityLabel,
            qualityScore: (float) $qualityScore,
            qcTime: (float) $qcTime,
        );
    }

    /**
     * @param array<mixed, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function normalizeStringKeyedMap(array $payload): array
    {
        $normalized = [];

        foreach (array_keys($payload) as $key) {
            if (! is_string($key)) {
                throw new RuntimeException('Worker payload keys must be strings.');
            }

            $normalized[$key] = $payload[$key];
        }

        return $normalized;
    }
}
