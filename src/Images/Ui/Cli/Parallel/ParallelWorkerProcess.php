<?php

declare(strict_types=1);

namespace App\Images\Ui\Cli\Parallel;

use Clue\React\NDJson\Decoder;
use Clue\React\NDJson\Encoder;
use React\ChildProcess\Process;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use RuntimeException;
use Throwable;

use function fclose;
use function is_array;
use function rewind;
use function sprintf;
use function stream_get_contents;
use function tmpfile;

/**
 * Owns one JPEG worker process and its NDJSON request/response connection.
 *
 * Adapted from Symplify EasyParallel's MIT-licensed ParallelProcess, itself
 * inspired by PHPStan's parallel process implementation.
 */
final class ParallelWorkerProcess
{
    private Process $process;

    private Encoder $encoder;

    /** @var resource|null */
    private $stdErr;

    /** @var callable(array<mixed, mixed>): void */
    private $onData;

    /** @var callable(Throwable): void */
    private $onError;

    private TimerInterface|null $timer = null;

    public function __construct(
        private readonly string $command,
        private readonly LoopInterface $loop,
        private readonly int $timeoutInSeconds,
    ) {
    }

    /**
     * @param callable(array<mixed, mixed>): void $onData
     * @param callable(Throwable): void           $onError
     * @param callable(int|null, string): void    $onExit
     */
    public function start(callable $onData, callable $onError, callable $onExit): void
    {
        $stdErr = tmpfile();
        if ($stdErr === false) {
            throw new RuntimeException('Failed creating worker stderr temp file.');
        }

        $this->stdErr  = $stdErr;
        $this->process = new Process($this->command, null, null, [2 => $stdErr]);
        $this->process->start($this->loop);

        $this->onData  = $onData;
        $this->onError = $onError;

        $this->process->on('exit', function (int|null $exitCode) use ($onExit): void {
            $stdErr = $this->stdErr;
            if ($stdErr === null) {
                throw new RuntimeException('Worker stderr stream is unavailable.');
            }

            $this->cancelTimer();
            rewind($stdErr);

            $streamContents = stream_get_contents($stdErr);
            $onExit($exitCode, $streamContents === false ? '' : $streamContents);

            fclose($stdErr);
            $this->stdErr = null;
        });
    }

    /** @param array<string, mixed> $data */
    public function request(array $data): void
    {
        $this->cancelTimer();
        $this->encoder->write($data);
        $this->timer = $this->loop->addTimer($this->timeoutInSeconds, function (): void {
            $onError = $this->onError;
            $onError(new RuntimeException(sprintf(
                'Child process timed out after %d seconds',
                $this->timeoutInSeconds,
            )));
        });
    }

    public function quit(): void
    {
        $this->cancelTimer();
        if (! $this->process->isRunning()) {
            return;
        }

        foreach ($this->process->pipes as $pipe) {
            $pipe->close();
        }

        $this->encoder->end();
    }

    public function bindConnection(Decoder $decoder, Encoder $encoder): void
    {
        $decoder->on('data', function (array $json): void {
            $this->cancelTimer();
            if (($json[ParallelProtocol::ACTION_KEY] ?? null) !== ParallelProtocol::RESULT_ACTION) {
                return;
            }

            $result = $json[ParallelProtocol::RESULT_KEY] ?? null;
            if (! is_array($result)) {
                return;
            }

            $onData = $this->onData;
            $onData($result);
        });
        $this->encoder = $encoder;

        $decoder->on('error', function (Throwable $throwable): void {
            $onError = $this->onError;
            $onError($throwable);
        });

        $encoder->on('error', function (Throwable $throwable): void {
            $onError = $this->onError;
            $onError($throwable);
        });
    }

    private function cancelTimer(): void
    {
        if (! $this->timer instanceof TimerInterface) {
            return;
        }

        $this->loop->cancelTimer($this->timer);
        $this->timer = null;
    }
}
