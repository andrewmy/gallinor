<?php

declare(strict_types=1);

namespace App\Images\Ui\Cli\Parallel;

use React\Socket\TcpServer;
use RuntimeException;

use function sprintf;

final class ParallelWorkerProcessPool
{
    /** @var array<string, ParallelWorkerProcess> */
    private array $processes = [];

    public function __construct(private readonly TcpServer $tcpServer)
    {
    }

    public function getProcess(string $identifier): ParallelWorkerProcess
    {
        if (! isset($this->processes[$identifier])) {
            throw new RuntimeException(sprintf('Worker process "%s" not found.', $identifier));
        }

        return $this->processes[$identifier];
    }

    public function attachProcess(string $identifier, ParallelWorkerProcess $process): void
    {
        $this->processes[$identifier] = $process;
    }

    public function tryQuitProcess(string $identifier): void
    {
        if (! isset($this->processes[$identifier])) {
            return;
        }

        $this->quitProcess($identifier);
    }

    private function quitProcess(string $identifier): void
    {
        $this->getProcess($identifier)->quit();

        unset($this->processes[$identifier]);
        if ($this->processes !== []) {
            return;
        }

        $this->tcpServer->close();
    }
}
