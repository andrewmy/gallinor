<?php

declare(strict_types=1);

namespace App\Images\Ui\Cli\Parallel;

use App\Images\Ui\Cli\AvifMigrationBatchResult;
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
use function dirname;
use function escapeshellarg;
use function file_exists;
use function implode;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function max;
use function min;
use function pathinfo;
use function sprintf;

use const DIRECTORY_SEPARATOR;
use const PATHINFO_FILENAME;
use const PHP_BINARY;

final readonly class ParallelAvifMigrationProcessor
{
    private const int STALE_RETENTION_SECONDS = 86_400;

    public function __construct(
        private CliHelper $cliHelper,
        private LoggerInterface $logger,
        private string $appPath,
    ) {
    }

    /** @param list<string> $avifPaths */
    public function process(
        OutputInterface $output,
        array $avifPaths,
        int $concurrency,
        int $workerMaxJobs,
        int $jobTimeout,
    ): AvifMigrationBatchResult {
        $totalJobs   = count($avifPaths);
        $progressBar = $this->cliHelper->createProgressBar($output, $totalJobs, 'AVIFs');
        $progressBar->start();

        $result = new AvifMigrationBatchResult();

        if ($totalJobs === 0) {
            $progressBar->setMessage('Done', 'status');
            $progressBar->finish();
            $output->writeln('');

            return $result;
        }

        ParallelTempDirectoryManager::pruneStaleWorkers(self::STALE_RETENTION_SECONDS);

        /** @var list<array{id: string, path: string, targetHeicPath: string, attempt: int}> $pendingJobs */
        $pendingJobs = [];
        foreach ($avifPaths as $index => $avifPath) {
            $targetHeicPath = $this->targetHeicPath($avifPath);
            if (file_exists($targetHeicPath)) {
                $result->skipped[$avifPath] = 'target HEIC already exists';
                $progressBar->advance();
                continue;
            }

            $pendingJobs[] = [
                'id'             => sprintf('job-%d', $index + 1),
                'path'           => $avifPath,
                'targetHeicPath' => $targetHeicPath,
                'attempt'        => 0,
            ];
        }

        if ($pendingJobs === []) {
            $progressBar->setMessage('Done', 'status');
            $progressBar->finish();
            $output->writeln('');

            return $result;
        }

        $orchestrator     = new ParallelWorkerPoolOrchestrator($this->logger);
        $retryPolicy      = new JobRetryPolicy();
        $requestedWorkers = min($concurrency, count($pendingJobs));
        $telemetry        = new ParallelConsoleTelemetry($output, $progressBar);

        $reportErrorForPath = static function (string $path, string $message) use ($progressBar, $result, $telemetry): void {
            if (isset($result->processed[$path]) || isset($result->skipped[$path]) || isset($result->errored[$path])) {
                return;
            }

            $result->errored[$path] = $message;
            $progressBar->setMessage(sprintf('%s | <error>Error</error>', basename($path)), 'status');
            $telemetry->printInlineError(sprintf('%s: %s', basename($path), $message));
        };

        $buildWorkerCommand = function (string $workerId, int $port) use ($workerMaxJobs): string {
            $commandParts = [
                PHP_BINARY,
                $this->appPath,
                'images:migrate-avif-to-heic:worker',
                sprintf('--port=%d', $port),
                sprintf('--identifier=%s', $workerId),
                sprintf('--worker-max-jobs=%d', max(1, $workerMaxJobs)),
            ];

            return implode(' ', array_map(static fn (string $part): string => escapeshellarg($part), $commandParts));
        };

        /** @param array{id: string, path: string, targetHeicPath: string, attempt: int} $job */
        $buildRequestPayload = static function (array $job): array {
            $jobId          = $job['id'] ?? null;
            $path           = $job['path'] ?? null;
            $targetHeicPath = $job['targetHeicPath'] ?? null;
            if (! is_string($jobId) || ! is_string($path) || ! is_string($targetHeicPath)) {
                throw new RuntimeException('Invalid AVIF worker job payload.');
            }

            return [
                ReactCommand::ACTION => Action::MAIN,
                Content::FILES       => [
                    [
                        'jobId'          => $jobId,
                        'path'           => $path,
                        'targetHeicPath' => $targetHeicPath,
                    ],
                ],
            ];
        };

        $handlePayload = function (array $payload) use ($output, $progressBar, &$result, $reportErrorForPath): ParallelWorkerPoolPayloadResult {
            $messageType = $payload['type'] ?? null;
            if (! is_string($messageType)) {
                return ParallelWorkerPoolPayloadResult::systemError();
            }

            if ($messageType === 'status') {
                $path    = $payload['path'] ?? null;
                $score   = $payload['score'] ?? null;
                $saved   = $payload['savedBytes'] ?? null;
                $quality = $payload['quality'] ?? null;

                if (! is_string($path)) {
                    return ParallelWorkerPoolPayloadResult::ignored();
                }

                if (
                    is_int($saved)
                    && is_int($quality)
                    && (is_float($score) || is_int($score))
                ) {
                    $delta        = -$saved;
                    $deltaSign    = $delta >= 0 ? '+' : '-';
                    $deltaAbs     = $delta >= 0 ? $delta : -$delta;
                    $runningTotal = $result->totalDeltaBytes() + $delta;
                    $totalSign    = $runningTotal >= 0 ? '+' : '-';
                    $totalAbs     = $runningTotal >= 0 ? $runningTotal : -$runningTotal;

                    $progressBar->setMessage(sprintf(
                        '%s | q=%d, score=%.1f, Δ=%s%s (total %s%s) | ok=%d skip=%d err=%d',
                        basename($path),
                        $quality,
                        (float) $score,
                        $deltaSign,
                        $this->cliHelper->formatBytes($deltaAbs),
                        $totalSign,
                        $this->cliHelper->formatBytes($totalAbs),
                        $result->processedCount(),
                        $result->skippedCount(),
                        $result->erroredCount(),
                    ), 'status');
                    if ($output->getVerbosity() < OutputInterface::VERBOSITY_VERY_VERBOSE) {
                        $progressBar->display();
                    }
                }

                return ParallelWorkerPoolPayloadResult::status();
            }

            if ($messageType !== 'result') {
                return ParallelWorkerPoolPayloadResult::systemError();
            }

            $path    = $payload['path'] ?? null;
            $outcome = $payload['outcome'] ?? null;

            if (! is_string($path) || ! is_string($outcome)) {
                return ParallelWorkerPoolPayloadResult::systemError();
            }

            if ($outcome === 'processed') {
                $processedResult = $payload['result'] ?? null;
                if (! is_array($processedResult)) {
                    return ParallelWorkerPoolPayloadResult::systemError();
                }

                $optimizedSize = $processedResult['optimizedSize'] ?? null;
                $originalSize  = $processedResult['originalSize'] ?? null;
                if (! is_int($optimizedSize) || ! is_int($originalSize)) {
                    return ParallelWorkerPoolPayloadResult::systemError();
                }

                $result->processed[$path] = $optimizedSize - $originalSize;
                unset($result->skipped[$path], $result->errored[$path]);

                return ParallelWorkerPoolPayloadResult::completed();
            }

            if ($outcome === 'skipped') {
                $skipReason = $payload['skipReason'] ?? null;
                if (! is_string($skipReason) || $skipReason === '') {
                    $skipReason = 'Skipped by worker.';
                }

                $result->skipped[$path] = $skipReason;
                unset($result->processed[$path], $result->errored[$path]);

                return ParallelWorkerPoolPayloadResult::completed();
            }

            if ($outcome === 'error') {
                $error = $payload['error'] ?? null;
                if (! is_string($error) || $error === '') {
                    $error = 'Unknown worker error.';
                }

                unset($result->processed[$path], $result->skipped[$path]);
                $reportErrorForPath($path, $error);

                return ParallelWorkerPoolPayloadResult::completed();
            }

            return ParallelWorkerPoolPayloadResult::systemError();
        };

        /** @param array{id: string, path: string, targetHeicPath: string, attempt: int} $job */
        $onJobTerminalFailure = static function (array $job, string $message) use ($reportErrorForPath): void {
            $path = $job['path'] ?? null;
            if (! is_string($path)) {
                return;
            }

            $reportErrorForPath($path, $message);
        };

        $orchestrator->run(
            progressBar: $progressBar,
            totalJobs: count($pendingJobs),
            pendingJobs: $pendingJobs,
            requestedWorkers: $requestedWorkers,
            workerMaxJobs: $workerMaxJobs,
            jobTimeout: $jobTimeout,
            retryPolicy: $retryPolicy,
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

    private function targetHeicPath(string $avifPath): string
    {
        return dirname($avifPath) . DIRECTORY_SEPARATOR . pathinfo($avifPath, PATHINFO_FILENAME) . '.heic';
    }
}
