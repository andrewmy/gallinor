<?php

declare(strict_types=1);

namespace App\Images\Ui\Cli\Parallel;

use App\Images\Domain\CalculationSkipReason;
use App\Images\Domain\ImageFile;
use App\Images\Ui\Cli\ImageBatchResult;
use App\Shared\Ui\Cli\CliHelper;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;
use Symplify\EasyParallel\Enum\Action;
use Symplify\EasyParallel\Enum\Content;
use Symplify\EasyParallel\Enum\ReactCommand;

use function array_map;
use function basename;
use function count;
use function escapeshellarg;
use function implode;
use function is_float;
use function is_int;
use function is_string;
use function max;
use function min;
use function sprintf;

use const PHP_BINARY;

final readonly class ParallelJpegProcessor
{
    private const int STALE_RETENTION_SECONDS = 86_400;

    public function __construct(
        private CliHelper $cliHelper,
        private LoggerInterface $logger,
        private string $appPath,
    ) {
    }

    /** @param list<ImageFile> $jpegs */
    public function process(
        OutputInterface $output,
        array $jpegs,
        int $concurrency,
        int $workerMaxJobs,
        int $jobTimeout,
        int|null $adaptiveStartWorkers = null,
    ): ImageBatchResult {
        $totalJobs   = count($jpegs);
        $progressBar = $this->cliHelper->createProgressBar($output, $totalJobs, 'JPEGs');
        $progressBar->start();

        $result = new ImageBatchResult();

        if ($totalJobs === 0) {
            $progressBar->setMessage('Done', 'status');
            $progressBar->finish();
            $output->writeln('');

            return $result;
        }

        ParallelTempDirectoryManager::pruneStaleWorkers(self::STALE_RETENTION_SECONDS);

        /** @var list<array{id: string, image: ImageFile, attempt: int}> $pendingJobs */
        $pendingJobs = [];
        foreach ($jpegs as $index => $image) {
            $pendingJobs[] = [
                'id'      => sprintf('job-%d', $index + 1),
                'image'   => $image,
                'attempt' => 0,
            ];
        }

        $payloadHandler    = new ParallelWorkerPayloadHandler();
        $orchestrator      = new ParallelWorkerPoolOrchestrator($this->logger);
        $retryPolicy       = new JobRetryPolicy();
        $requestedWorkers  = min($concurrency, $totalJobs);
        $adaptiveStart     = $adaptiveStartWorkers === null
            ? null
            : min($requestedWorkers, max(1, $adaptiveStartWorkers));
        $totalSavingsBytes = 0;
        $telemetry         = new ParallelConsoleTelemetry($output, $progressBar);

        $buildWorkerCommand = function (string $workerId, int $port) use ($workerMaxJobs): string {
            $commandParts = [
                PHP_BINARY,
                $this->appPath,
                'images:squeeze:worker',
                sprintf('--port=%d', $port),
                sprintf('--identifier=%s', $workerId),
                sprintf('--worker-max-jobs=%d', max(1, $workerMaxJobs)),
            ];

            return implode(' ', array_map(static fn (string $part): string => escapeshellarg($part), $commandParts));
        };

        /** @param array{id: string, image: ImageFile, attempt: int} $job */
        $buildRequestPayload = static function (array $job): array {
            $jobId = $job['id'] ?? null;
            $image = $job['image'] ?? null;
            if (! is_string($jobId) || ! $image instanceof ImageFile) {
                throw new RuntimeException('Invalid JPEG worker job payload.');
            }

            return [
                ReactCommand::ACTION => Action::MAIN,
                Content::FILES       => [
                    [
                        'jobId'  => $jobId,
                        'path'   => $image->path,
                    ],
                ],
            ];
        };

        $handlePayload = function (array $payload) use (
            $payloadHandler,
            &$result,
            &$totalSavingsBytes,
            $output,
            $progressBar,
            $telemetry,
        ): ParallelWorkerPoolPayloadResult {
            $handlingResult = $payloadHandler->handle($payload, $result);
            if ($handlingResult->countAsSystemError) {
                return ParallelWorkerPoolPayloadResult::systemError();
            }

            if ($handlingResult->isStatus) {
                if (
                    is_string($handlingResult->path)
                    && is_int($handlingResult->quality)
                    && is_int($handlingResult->savedBytes)
                    && is_float($handlingResult->score)
                ) {
                    $runningTotal = $totalSavingsBytes + $handlingResult->savedBytes;

                    $progressBar->setMessage(sprintf(
                        '%s | %s=%d, score=%.1f, saved %s (total: %s)',
                        basename($handlingResult->path),
                        'q',
                        $handlingResult->quality,
                        $handlingResult->score,
                        $this->cliHelper->formatBytes($handlingResult->savedBytes),
                        $this->cliHelper->formatBytes($runningTotal),
                    ), 'status');
                    if ($output->getVerbosity() < OutputInterface::VERBOSITY_VERY_VERBOSE) {
                        $progressBar->display();
                    }
                }

                return ParallelWorkerPoolPayloadResult::status();
            }

            if (! $handlingResult->isCompleted || ! is_string($handlingResult->path) || ! is_string($handlingResult->outcome)) {
                return ParallelWorkerPoolPayloadResult::ignored();
            }

            if ($handlingResult->outcome === 'processed') {
                $totalSavingsBytes += $handlingResult->savedDelta;
            } elseif ($handlingResult->outcome === 'skipped') {
                if (! $handlingResult->skipReason instanceof CalculationSkipReason) {
                    return ParallelWorkerPoolPayloadResult::systemError();
                }

                $progressBar->setMessage(sprintf('%s | <comment>Skipped</comment>', basename($handlingResult->path)), 'status');
                $progressBar->clear();
                $output->writeln(sprintf('<comment>Skipped: %s (%s)</comment>', basename($handlingResult->path), $handlingResult->skipReason->value));
                $progressBar->display();
            } elseif ($handlingResult->outcome === 'error') {
                if (! is_string($handlingResult->error)) {
                    return ParallelWorkerPoolPayloadResult::systemError();
                }

                $progressBar->setMessage(sprintf('%s | <error>Error</error>', basename($handlingResult->path)), 'status');
                $telemetry->printInlineError(sprintf('%s: %s', $handlingResult->path, $handlingResult->error));
            } else {
                return ParallelWorkerPoolPayloadResult::systemError();
            }

            return ParallelWorkerPoolPayloadResult::completed();
        };

        /** @param array{id: string, image: ImageFile, attempt: int} $job */
        $onJobTerminalFailure = static function (array $job, string $message) use ($progressBar, $result, $telemetry): void {
            $image = $job['image'] ?? null;
            if (! $image instanceof ImageFile) {
                return;
            }

            $path = $image->path;
            if (isset($result->processed[$path]) || isset($result->skipped[$path]) || isset($result->errored[$path])) {
                return;
            }

            $result->errored[$path] = $message;
            $progressBar->setMessage(sprintf('%s | <error>Error</error>', basename($path)), 'status');
            $telemetry->printInlineError(sprintf('%s: %s', $path, $message));
        };

        $orchestrator->run(
            progressBar: $progressBar,
            totalJobs: $totalJobs,
            pendingJobs: $pendingJobs,
            requestedWorkers: $requestedWorkers,
            workerMaxJobs: $workerMaxJobs,
            jobTimeout: $jobTimeout,
            retryPolicy: $retryPolicy,
            adaptiveStartWorkers: $adaptiveStart,
            buildWorkerCommand: $buildWorkerCommand,
            buildRequestPayload: $buildRequestPayload,
            handlePayload: $handlePayload,
            onJobTerminalFailure: $onJobTerminalFailure,
            trace: $telemetry->trace(...),
            onWorkerUpdate: $telemetry->onWorkerUpdate(...),
        );

        $telemetry->finish();

        $progressBar->setMessage('Done', 'status');
        $progressBar->finish();
        $output->writeln('');

        return $result;
    }
}
