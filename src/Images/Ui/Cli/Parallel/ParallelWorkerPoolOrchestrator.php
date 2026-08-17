<?php

declare(strict_types=1);

namespace App\Images\Ui\Cli\Parallel;

use Clue\React\NDJson\Decoder;
use Clue\React\NDJson\Encoder;
use Psr\Log\LoggerInterface;
use React\EventLoop\StreamSelectLoop;
use React\Socket\ConnectionInterface;
use React\Socket\TcpServer;
use RuntimeException;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

use function abs;
use function array_keys;
use function array_shift;
use function basename;
use function count;
use function getmypid;
use function implode;
use function is_float;
use function is_int;
use function is_object;
use function is_string;
use function max;
use function microtime;
use function parse_url;
use function property_exists;
use function sprintf;
use function strlen;
use function strtok;
use function substr;
use function trim;

use const PHP_URL_PORT;

final readonly class ParallelWorkerPoolOrchestrator
{
    private const int SYSTEM_ERROR_LIMIT                   = 50;
    private const int STATUS_FRAME_MAX_BYTES               = 4 * 1024 * 1024;
    private const int WORKER_TICK_SECONDS                  = 1;
    private const int WORKER_CONNECT_TIMEOUT_SECONDS       = 20;
    private const int ADAPTIVE_WINDOW_COMPLETIONS          = 12;
    private const float ADAPTIVE_MIN_THROUGHPUT_GAIN_RATIO = 0.03;

    public function __construct(private LoggerInterface $logger)
    {
    }

    /**
     * @param array<int, array<int|string, mixed>>                           $pendingJobs
     * @param callable(string, int): string                                  $buildWorkerCommand
     * @param callable(array<int|string, mixed>): array<string, mixed>       $buildRequestPayload
     * @param callable(array<mixed, mixed>): ParallelWorkerPoolPayloadResult $handlePayload
     * @param callable(array<int|string, mixed>, string): void               $onJobTerminalFailure
     * @param (callable(string, int): void)|null                             $trace
     * @param (callable(string, string): void)|null                          $onWorkerUpdate
     */
    public function run(
        ProgressBar $progressBar,
        int $totalJobs,
        array &$pendingJobs,
        int $requestedWorkers,
        int $workerMaxJobs,
        int $jobTimeout,
        JobRetryPolicy $retryPolicy,
        callable $buildWorkerCommand,
        callable $buildRequestPayload,
        callable $handlePayload,
        callable $onJobTerminalFailure,
        int|null $adaptiveStartWorkers = null,
        callable|null $trace = null,
        callable|null $onWorkerUpdate = null,
    ): void {
        if ($totalJobs <= 0 || $pendingJobs === [] || $requestedWorkers <= 0) {
            return;
        }

        $systemErrors     = 0;
        $completedJobs    = 0;
        $workerSeq        = 0;
        $adaptiveState    = new AdaptiveConcurrencyState($requestedWorkers, $adaptiveStartWorkers);
        $requestedWorkers = $adaptiveState->initialRequestedWorkers;

        $runId                = sprintf('run-%d-%d', getmypid(), (int) (microtime(true) * 1_000_000));
        $traceEvent           = static function (int $verbosity, string $message) use ($trace): void {
            if ($trace === null) {
                return;
            }

            $trace($message, $verbosity);
        };
        $updateWorker         = static function (string $workerId, string $state) use ($onWorkerUpdate): void {
            if ($onWorkerUpdate === null) {
                return;
            }

            $onWorkerUpdate($workerId, $state);
        };
        $jobId                = static function (array $job): string {
            $id = $job['id'] ?? null;
            if (is_string($id) && $id !== '') {
                return $id;
            }

            return '[unknown-job]';
        };
        $statusGist           = static function (array $payload): string {
            $path         = $payload['path'] ?? null;
            $phase        = $payload['phase'] ?? null;
            $quality      = $payload['quality'] ?? null;
            $score        = $payload['score'] ?? null;
            $savedBytes   = $payload['savedBytes'] ?? null;
            $deltaAgainst = $payload['deltaAgainst'] ?? null;
            $decision     = $payload['decision'] ?? null;
            $formatDelta  = static function (int $saved): string {
                $deltaBytes = -$saved;
                $absBytes   = abs($deltaBytes);
                $sign       = $deltaBytes >= 0 ? '+' : '-';

                if ($absBytes >= 1024 * 1024) {
                    return sprintf('%s%.1fMB', $sign, $absBytes / (1024 * 1024));
                }

                if ($absBytes >= 1024) {
                    return sprintf('%s%.0fKB', $sign, $absBytes / 1024);
                }

                return sprintf('%s%dB', $sign, $absBytes);
            };

            $parts = [];
            if (is_string($phase) && $phase !== '') {
                $parts[] = $phase;
            }

            if (is_string($path) && $path !== '') {
                $parts[] = basename($path);
            }

            if (is_int($quality)) {
                $parts[] = sprintf('q=%d', $quality);
            }

            if (is_int($score) || is_float($score)) {
                $parts[] = sprintf('s=%.1f', (float) $score);
            }

            if (is_int($savedBytes)) {
                $parts[] = sprintf('Δ=%s', $formatDelta($savedBytes));
            }

            if (is_int($savedBytes) && is_string($deltaAgainst) && $deltaAgainst !== '') {
                $parts[] = sprintf('vs %s', $deltaAgainst);
            }

            if (is_string($decision) && $decision !== '') {
                $parts[] = sprintf('[%s]', $decision);
            }

            if ($parts === []) {
                return 'status';
            }

            return implode(' ', $parts);
        };
        $jobGist              = static function (array $job) use ($jobId): string {
            $path = $job['path'] ?? null;
            if (is_string($path) && $path !== '') {
                return basename($path);
            }

            $image = $job['image'] ?? null;
            if (is_object($image) && property_exists($image, 'path') && is_string($image->path) && $image->path !== '') {
                return basename($image->path);
            }

            return $jobId($job);
        };
        $updateAdaptiveTarget = static function () use (
            $adaptiveState,
            &$requestedWorkers,
            $traceEvent,
        ): void {
            if (! $adaptiveState->enabled) {
                return;
            }

            $now = microtime(true);

            if (! is_float($adaptiveState->windowStartedAt)) {
                $adaptiveState->windowStartedAt = $now;
            }

            $adaptiveState->windowCompleted++;
            if ($adaptiveState->windowCompleted < self::ADAPTIVE_WINDOW_COMPLETIONS) {
                return;
            }

            $windowStartedAt   = $adaptiveState->windowStartedAt;
            $windowCompletions = $adaptiveState->windowCompleted;
            $durationSeconds   = max(0.001, $now - $windowStartedAt);
            $throughput        = $windowCompletions / $durationSeconds;

            $adaptiveState->windowStartedAt = null;
            $adaptiveState->windowCompleted = 0;

            $previousThroughput = $adaptiveState->lastThroughput;
            if (! is_float($previousThroughput)) {
                $adaptiveState->lastThroughput = $throughput;

                if ($requestedWorkers < $adaptiveState->maxWorkers) {
                    $requestedWorkers++;
                    $traceEvent(
                        OutputInterface::VERBOSITY_VERBOSE,
                        sprintf(
                            '[parallel] adaptive scale-up to %d workers (warm-up throughput %.2f jobs/s)',
                            $requestedWorkers,
                            $throughput,
                        ),
                    );
                }

                return;
            }

            $gainRatio = $previousThroughput > 0 ? ($throughput - $previousThroughput) / $previousThroughput : 1.0;
            if (
                $requestedWorkers < $adaptiveState->maxWorkers
                && $gainRatio > self::ADAPTIVE_MIN_THROUGHPUT_GAIN_RATIO
            ) {
                $requestedWorkers++;
                $adaptiveState->lastThroughput = $throughput;
                $traceEvent(
                    OutputInterface::VERBOSITY_VERBOSE,
                    sprintf(
                        '[parallel] adaptive scale-up to %d workers (gain %.1f%%, throughput %.2f jobs/s)',
                        $requestedWorkers,
                        $gainRatio * 100,
                        $throughput,
                    ),
                );

                return;
            }

            $adaptiveState->enabled = false;
            $traceEvent(
                OutputInterface::VERBOSITY_VERBOSE,
                sprintf(
                    '[parallel] adaptive scaling locked at %d workers (gain %.1f%% <= %.1f%%)',
                    $requestedWorkers,
                    $gainRatio * 100,
                    self::ADAPTIVE_MIN_THROUGHPUT_GAIN_RATIO * 100,
                ),
            );
        };

        $traceEvent(
            OutputInterface::VERBOSITY_VERBOSE,
            sprintf(
                '[parallel:%s] starting (jobs=%d, workers=%d%s, worker-max-jobs=%d, timeout=%ds)',
                $runId,
                $totalJobs,
                $requestedWorkers,
                $adaptiveState->enabled ? sprintf('..%d adaptive', $adaptiveState->maxWorkers) : '',
                $workerMaxJobs,
                $jobTimeout,
            ),
        );

        /**
         * @var array<string, array{
         *   connected: bool,
         *   expectedStop: bool,
         *   jobsProcessed: int,
         *   spawnedAt: float
         * }> $workers
         */
        $workers = [];

        /** @var array<string, array{job: array<int|string, mixed>, lastMessageAt: float}> $inFlight */
        $inFlight = [];

        $loop        = new StreamSelectLoop();
        $tcpServer   = new TcpServer('127.0.0.1:0', $loop);
        $processPool = new ParallelWorkerProcessPool($tcpServer);

        $address = (string) $tcpServer->getAddress();
        $port    = (int) parse_url($address, PHP_URL_PORT);
        if ($port <= 0) {
            throw new RuntimeException(sprintf('Invalid worker server address: %s', $address));
        }

        $tryQuitWorker = static function (string $workerId, string $reason = '') use (&$workers, &$systemErrors, $processPool, $traceEvent, $updateWorker): void {
            if (! isset($workers[$workerId])) {
                return;
            }

            if (! $workers[$workerId]['connected']) {
                $traceEvent(
                    OutputInterface::VERBOSITY_DEBUG,
                    sprintf(
                        '[parallel] skip quit for %s before bind%s',
                        $workerId,
                        $reason !== '' ? sprintf(' (%s)', $reason) : '',
                    ),
                );

                return;
            }

            try {
                $processPool->tryQuitProcess($workerId);
            } catch (Throwable $throwable) {
                $traceEvent(
                    OutputInterface::VERBOSITY_VERBOSE,
                    sprintf(
                        '[parallel] quit failed for %s%s: %s',
                        $workerId,
                        $reason !== '' ? sprintf(' (%s)', $reason) : '',
                        $throwable->getMessage(),
                    ),
                );
                $updateWorker($workerId, 'quit-failed');
                $systemErrors++;
                unset($workers[$workerId]);
            }
        };

        $retryOrFail = static function (
            array $job,
            string $message,
        ) use (
            $retryPolicy,
            &$pendingJobs,
            &$systemErrors,
            &$completedJobs,
            $progressBar,
            $onJobTerminalFailure,
            $traceEvent,
            $jobId,
        ): void {
            $attempt = $job['attempt'] ?? null;
            if (! is_int($attempt)) {
                $systemErrors++;
                $traceEvent(
                    OutputInterface::VERBOSITY_DEBUG,
                    sprintf('[parallel] terminal job failure %s (missing attempt): %s', $jobId($job), $message),
                );
                $onJobTerminalFailure($job, $message);
                $progressBar->advance();
                $completedJobs++;

                return;
            }

            $nextAttempt = $retryPolicy->nextAttemptNumber($attempt);
            if ($nextAttempt === null) {
                $traceEvent(
                    OutputInterface::VERBOSITY_VERBOSE,
                    sprintf('[parallel] terminal job failure %s after attempt %d: %s', $jobId($job), $attempt, $message),
                );
                $onJobTerminalFailure($job, $message);
                $progressBar->advance();
                $completedJobs++;

                return;
            }

            $job['attempt'] = $nextAttempt;
            $pendingJobs[]  = $job;
            $traceEvent(
                OutputInterface::VERBOSITY_VERY_VERBOSE,
                sprintf('[parallel] requeued %s attempt %d -> %d: %s', $jobId($job), $attempt, $nextAttempt, $message),
            );
        };

        $dispatchIfPossible = static function (string $workerId) use (
            &$workers,
            &$pendingJobs,
            &$inFlight,
            &$systemErrors,
            $processPool,
            $tryQuitWorker,
            $buildRequestPayload,
            $traceEvent,
            $jobId,
            $jobGist,
            $updateWorker,
        ): void {
            if ($pendingJobs === [] || ! isset($workers[$workerId])) {
                return;
            }

            if (! $workers[$workerId]['connected'] || isset($inFlight[$workerId])) {
                return;
            }

            $job     = array_shift($pendingJobs);
            $attempt = $job['attempt'] ?? null;
            $traceEvent(
                OutputInterface::VERBOSITY_VERY_VERBOSE,
                sprintf(
                    '[parallel] dispatch %s -> %s (attempt=%s, queue=%d)',
                    $jobId($job),
                    $workerId,
                    is_int($attempt) ? (string) $attempt : '?',
                    count($pendingJobs),
                ),
            );
            $updateWorker($workerId, sprintf('work %s', $jobGist($job)));

            try {
                $processPool->getProcess($workerId)->request($buildRequestPayload($job));
            } catch (Throwable $throwable) {
                $systemErrors++;
                $pendingJobs[]                      = $job;
                $workers[$workerId]['expectedStop'] = true;
                $tryQuitWorker($workerId, 'dispatch-failed');
                $traceEvent(
                    OutputInterface::VERBOSITY_VERBOSE,
                    sprintf('[parallel] dispatch failed %s -> %s: %s', $jobId($job), $workerId, $throwable->getMessage()),
                );
                $updateWorker($workerId, 'dispatch-failed');

                return;
            }

            $inFlight[$workerId] = [
                'job'           => $job,
                'lastMessageAt' => microtime(true),
            ];
        };

        $spawnWorker = static function () use (
            &$workers,
            &$workerSeq,
            $runId,
            $port,
            $jobTimeout,
            $loop,
            &$inFlight,
            &$systemErrors,
            &$completedJobs,
            $progressBar,
            &$pendingJobs,
            $workerMaxJobs,
            $totalJobs,
            &$requestedWorkers,
            &$spawnWorker,
            $dispatchIfPossible,
            $processPool,
            $tryQuitWorker,
            $buildWorkerCommand,
            $handlePayload,
            $retryOrFail,
            $updateAdaptiveTarget,
            $traceEvent,
            $jobId,
            $statusGist,
            $updateWorker,
        ): string {
            $workerSeq++;
            $workerId = sprintf('%s-worker-%d', $runId, $workerSeq);

            $command = $buildWorkerCommand($workerId, $port);
            $process = new ParallelWorkerProcess($command, $loop, max(self::WORKER_TICK_SECONDS, $jobTimeout));

            $traceEvent(OutputInterface::VERBOSITY_VERBOSE, sprintf('[parallel] spawned worker %s', $workerId));
            $traceEvent(OutputInterface::VERBOSITY_DEBUG, sprintf('[parallel] worker command ready %s', $workerId));
            $updateWorker($workerId, 'spawned');

            $process->start(
                static function (array $payload) use (
                    $workerId,
                    &$workers,
                    &$inFlight,
                    &$pendingJobs,
                    &$systemErrors,
                    &$completedJobs,
                    $progressBar,
                    $workerMaxJobs,
                    $totalJobs,
                    &$requestedWorkers,
                    &$spawnWorker,
                    $dispatchIfPossible,
                    $tryQuitWorker,
                    $handlePayload,
                    $traceEvent,
                    $jobId,
                    $statusGist,
                    $updateAdaptiveTarget,
                    $updateWorker,
                ): void {
                    if (! isset($workers[$workerId])) {
                        return;
                    }

                    if (isset($inFlight[$workerId])) {
                        $inFlight[$workerId]['lastMessageAt'] = microtime(true);
                    }

                    $outcome = $handlePayload($payload);
                    if ($outcome->countAsSystemError) {
                        $systemErrors++;
                        $traceEvent(OutputInterface::VERBOSITY_DEBUG, sprintf('[parallel] payload error on %s', $workerId));
                        $updateWorker($workerId, 'payload-error');

                        return;
                    }

                    if ($outcome->isStatus) {
                        $updateWorker($workerId, $statusGist($payload));

                        return;
                    }

                    if (! $outcome->isCompleted) {
                        $traceEvent(OutputInterface::VERBOSITY_DEBUG, sprintf('[parallel] ignored payload from %s', $workerId));

                        return;
                    }

                    if (! isset($inFlight[$workerId])) {
                        $systemErrors++;
                        $traceEvent(
                            OutputInterface::VERBOSITY_VERBOSE,
                            sprintf('[parallel] completion received for %s without in-flight job', $workerId),
                        );
                        $updateWorker($workerId, 'orphan-complete');

                        return;
                    }

                    $finishedJobId = $jobId($inFlight[$workerId]['job']);
                    unset($inFlight[$workerId]);
                    $workers[$workerId]['jobsProcessed']++;
                    $completedJobs++;
                    $progressBar->advance();
                    $traceEvent(
                        OutputInterface::VERBOSITY_VERY_VERBOSE,
                        sprintf('[parallel] completed %s on %s (%d/%d)', $finishedJobId, $workerId, $completedJobs, $totalJobs),
                    );
                    $updateWorker($workerId, 'idle');
                    $updateAdaptiveTarget();

                    if ($workers[$workerId]['jobsProcessed'] >= $workerMaxJobs) {
                        $traceEvent(
                            OutputInterface::VERBOSITY_VERBOSE,
                            sprintf('[parallel] worker %s reached max jobs (%d)', $workerId, $workerMaxJobs),
                        );
                        $updateWorker($workerId, 'recycling');
                        if ($pendingJobs !== [] && count($workers) < $requestedWorkers + 1) {
                            $spawnWorker();
                        }

                        $workers[$workerId]['expectedStop'] = true;
                        $tryQuitWorker($workerId, 'worker-max-jobs');

                        return;
                    }

                    $dispatchIfPossible($workerId);
                },
                static function (Throwable $throwable) use (
                    $workerId,
                    &$workers,
                    &$inFlight,
                    &$systemErrors,
                    $tryQuitWorker,
                    $retryOrFail,
                    $traceEvent,
                    $updateWorker,
                ): void {
                    $systemErrors++;
                    $traceEvent(
                        OutputInterface::VERBOSITY_VERBOSE,
                        sprintf('[parallel] worker runtime error on %s: %s', $workerId, $throwable->getMessage()),
                    );
                    $updateWorker($workerId, 'runtime-error');

                    if (isset($inFlight[$workerId])) {
                        $job = $inFlight[$workerId]['job'];
                        unset($inFlight[$workerId]);
                        $retryOrFail($job, $throwable->getMessage());
                    }

                    if (! isset($workers[$workerId])) {
                        return;
                    }

                    $workers[$workerId]['expectedStop'] = true;
                    $tryQuitWorker($workerId, 'runtime-error');
                },
                static function (
                    int|null $exitCode,
                    string $stdErr,
                ) use (
                    $workerId,
                    &$workers,
                    &$inFlight,
                    &$pendingJobs,
                    &$systemErrors,
                    &$requestedWorkers,
                    &$spawnWorker,
                    $retryOrFail,
                    $traceEvent,
                    $updateWorker,
                ): void {
                    $expectedStop    = $workers[$workerId]['expectedStop'] ?? false;
                    $stdErrFirstLine = trim(strtok($stdErr, "\r\n") ?: '');
                    if ($stdErrFirstLine !== '' && strlen($stdErrFirstLine) > 120) {
                        $stdErrFirstLine = substr($stdErrFirstLine, 0, 117) . '...';
                    }

                    $traceEvent(
                        OutputInterface::VERBOSITY_VERBOSE,
                        sprintf(
                            '[parallel] worker exit %s (code=%s expected=%s%s)',
                            $workerId,
                            $exitCode === null ? 'null' : (string) $exitCode,
                            $expectedStop ? 'yes' : 'no',
                            $stdErrFirstLine !== '' ? ', stderr=' . $stdErrFirstLine : '',
                        ),
                    );
                    $updateWorker($workerId, $expectedStop ? 'exited' : sprintf(
                        'exit-error code=%s%s',
                        $exitCode === null ? 'null' : (string) $exitCode,
                        $stdErrFirstLine !== '' ? sprintf(' (%s)', $stdErrFirstLine) : '',
                    ));

                    if (isset($inFlight[$workerId])) {
                        $job = $inFlight[$workerId]['job'];
                        unset($inFlight[$workerId]);
                        $retryOrFail($job, sprintf('Worker exited unexpectedly%s', $stdErr !== '' ? ': ' . $stdErr : '.'));
                    }

                    if (! $expectedStop && $exitCode !== 0) {
                        $systemErrors++;
                    }

                    unset($workers[$workerId]);

                    if ($pendingJobs === [] || count($workers) >= $requestedWorkers) {
                        return;
                    }

                    $spawnWorker();
                },
            );

            $processPool->attachProcess($workerId, $process);
            $workers[$workerId] = [
                'connected'     => false,
                'expectedStop'  => false,
                'jobsProcessed' => 0,
                'spawnedAt'     => microtime(true),
            ];

            return $workerId;
        };

        $tcpServer->on('connection', function (ConnectionInterface $connection) use (&$workers, &$systemErrors, $dispatchIfPossible, $processPool, $traceEvent, $updateWorker): void {
            $decoder = new Decoder($connection, true, 512, 0, self::STATUS_FRAME_MAX_BYTES);
            $encoder = new Encoder($connection);

            $decoder->on('data', static function (array $json) use (&$workers, &$systemErrors, $dispatchIfPossible, $decoder, $encoder, $processPool, $traceEvent, $updateWorker): void {
                $action = $json[ParallelProtocol::ACTION_KEY] ?? null;
                if ($action !== ParallelProtocol::HELLO_ACTION) {
                    return;
                }

                $workerId = $json[ParallelProtocol::IDENTIFIER_KEY] ?? null;
                if (! is_string($workerId) || ! isset($workers[$workerId])) {
                    $systemErrors++;

                    return;
                }

                try {
                    $processPool->getProcess($workerId)->bindConnection($decoder, $encoder);
                } catch (Throwable $throwable) {
                    $systemErrors++;
                    $traceEvent(
                        OutputInterface::VERBOSITY_VERBOSE,
                        sprintf('[parallel] worker bind failed %s: %s', $workerId, $throwable->getMessage()),
                    );
                    $updateWorker($workerId, 'bind-failed');
                    unset($workers[$workerId]);

                    return;
                }

                $workers[$workerId]['connected'] = true;
                $traceEvent(OutputInterface::VERBOSITY_VERBOSE, sprintf('[parallel] worker connected %s', $workerId));
                $updateWorker($workerId, 'ready');
                $dispatchIfPossible($workerId);
            });

            $decoder->on('error', function (Throwable $throwable) use (&$systemErrors, $traceEvent): void {
                $this->logger->error('Parallel worker decoder error.', ['error' => $throwable->getMessage()]);
                $traceEvent(
                    OutputInterface::VERBOSITY_VERBOSE,
                    sprintf('[parallel] decoder error: %s', $throwable->getMessage()),
                );
                $systemErrors++;
            });

            $encoder->on('error', function (Throwable $throwable) use (&$systemErrors, $traceEvent): void {
                $this->logger->error('Parallel worker encoder error.', ['error' => $throwable->getMessage()]);
                $traceEvent(
                    OutputInterface::VERBOSITY_VERBOSE,
                    sprintf('[parallel] encoder error: %s', $throwable->getMessage()),
                );
                $systemErrors++;
            });
        });

        for ($i = 0; $i < $requestedWorkers; $i++) {
            $spawnWorker();
        }

        $loop->addPeriodicTimer(0.1, function () use (
            &$workers,
            &$inFlight,
            $jobTimeout,
            &$systemErrors,
            &$pendingJobs,
            &$requestedWorkers,
            &$spawnWorker,
            $dispatchIfPossible,
            $tryQuitWorker,
            &$completedJobs,
            $totalJobs,
            $loop,
            $tcpServer,
            $retryOrFail,
            $onJobTerminalFailure,
            $progressBar,
            $traceEvent,
            $updateWorker,
        ): void {
            $now = microtime(true);

            if ($jobTimeout > 0) {
                foreach ($inFlight as $workerId => $state) {
                    if ($now - $state['lastMessageAt'] <= $jobTimeout) {
                        continue;
                    }

                    $systemErrors++;
                    $job = $state['job'];
                    unset($inFlight[$workerId]);
                    $traceEvent(
                        OutputInterface::VERBOSITY_VERBOSE,
                        sprintf('[parallel] timeout on %s after %ds', $workerId, $jobTimeout),
                    );
                    $updateWorker($workerId, 'timeout');
                    $retryOrFail($job, sprintf('Worker timed out after %d seconds of inactivity.', $jobTimeout));

                    if (! isset($workers[$workerId])) {
                        continue;
                    }

                    $workers[$workerId]['expectedStop'] = true;
                    $tryQuitWorker($workerId, 'timeout');
                }
            }

            foreach (array_keys($workers) as $workerId) {
                if ($workers[$workerId]['connected']) {
                    continue;
                }

                if ($now - $workers[$workerId]['spawnedAt'] <= self::WORKER_CONNECT_TIMEOUT_SECONDS) {
                    continue;
                }

                $systemErrors++;
                $traceEvent(
                    OutputInterface::VERBOSITY_VERBOSE,
                    sprintf(
                        '[parallel] worker connect timeout %s after %ds',
                        $workerId,
                        self::WORKER_CONNECT_TIMEOUT_SECONDS,
                    ),
                );
                $updateWorker($workerId, 'connect-timeout');
                unset($workers[$workerId]);
            }

            if ($pendingJobs !== [] && count($workers) < $requestedWorkers) {
                $traceEvent(
                    OutputInterface::VERBOSITY_VERY_VERBOSE,
                    sprintf('[parallel] worker deficit detected (live=%d, target=%d)', count($workers), $requestedWorkers),
                );
                while (count($workers) < $requestedWorkers) {
                    $spawnWorker();
                }
            }

            foreach (array_keys($workers) as $workerId) {
                $dispatchIfPossible($workerId);
            }

            if ($systemErrors > self::SYSTEM_ERROR_LIMIT) {
                $traceEvent(
                    OutputInterface::VERBOSITY_VERBOSE,
                    sprintf('[parallel] aborting: system error limit reached (%d)', $systemErrors),
                );
                $this->abortOutstandingJobs(
                    pendingJobs: $pendingJobs,
                    inFlight: $inFlight,
                    message: 'Parallel processing aborted: system error limit reached.',
                    onJobTerminalFailure: $onJobTerminalFailure,
                    progressBar: $progressBar,
                    completedJobs: $completedJobs,
                    trace: $traceEvent,
                );

                foreach (array_keys($workers) as $workerId) {
                    $tryQuitWorker($workerId, 'system-error-limit');
                }

                $tcpServer->close();
                $loop->stop();

                return;
            }

            if ($completedJobs >= $totalJobs) {
                $traceEvent(
                    OutputInterface::VERBOSITY_VERBOSE,
                    sprintf('[parallel] all jobs completed (%d/%d)', $completedJobs, $totalJobs),
                );
                foreach (array_keys($workers) as $workerId) {
                    $tryQuitWorker($workerId, 'all-jobs-complete');
                }

                $tcpServer->close();
                $loop->stop();

                return;
            }

            if ($workers !== [] || $pendingJobs === []) {
                return;
            }

            $this->abortOutstandingJobs(
                pendingJobs: $pendingJobs,
                inFlight: $inFlight,
                message: 'Parallel processing aborted: no live workers available.',
                onJobTerminalFailure: $onJobTerminalFailure,
                progressBar: $progressBar,
                completedJobs: $completedJobs,
                trace: $traceEvent,
            );

            $tcpServer->close();
            $loop->stop();
        });

        $loop->run();
        $traceEvent(
            OutputInterface::VERBOSITY_VERBOSE,
            sprintf('[parallel:%s] finished (completed=%d, errors=%d)', $runId, $completedJobs, $systemErrors),
        );
    }

    /**
     * @param array<int, array<int|string, mixed>>                                      $pendingJobs
     * @param array<string, array{job: array<int|string, mixed>, lastMessageAt: float}> $inFlight
     * @param callable(array<int|string, mixed>, string): void                          $onJobTerminalFailure
     * @param callable(int, string): void|null                                          $trace
     */
    private function abortOutstandingJobs(
        array &$pendingJobs,
        array &$inFlight,
        string $message,
        callable $onJobTerminalFailure,
        ProgressBar $progressBar,
        int &$completedJobs,
        callable|null $trace = null,
    ): void {
        /** @var array<string, array<int|string, mixed>> $remainingById */
        $remainingById = [];

        foreach ($pendingJobs as $job) {
            $remainingById[$this->jobIdentifier($job)] = $job;
        }

        foreach ($inFlight as $state) {
            $remainingById[$this->jobIdentifier($state['job'])] = $state['job'];
        }

        $pendingJobs = [];
        $inFlight    = [];
        if ($trace !== null) {
            $trace(OutputInterface::VERBOSITY_VERBOSE, sprintf('[parallel] aborting %d remaining jobs: %s', count($remainingById), $message));
        }

        foreach ($remainingById as $job) {
            $onJobTerminalFailure($job, $message);
            $progressBar->advance();
            $completedJobs++;
        }
    }

    /** @param array<int|string, mixed> $job */
    private function jobIdentifier(array $job): string
    {
        $id = $job['id'] ?? null;
        if (is_string($id) && $id !== '') {
            return $id;
        }

        return ParallelJsonEncoder::encode($job);
    }
}
