<?php

declare(strict_types=1);

namespace App\Domain;

use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

use function explode;
use function in_array;
use function max;
use function sprintf;
use function trim;

use const PHP_OS_FAMILY;

final readonly class Platform
{
    private const string OS_DARWIN  = 'Darwin';
    private const string OS_WINDOWS = 'Windows';

    public string $os;
    public int $nCores;

    public function __construct(
        string|null $os = null,
        int|null $nCores = null,
    ) {
        $this->os = $os ?? PHP_OS_FAMILY;
        if (! in_array($this->os, [self::OS_DARWIN, self::OS_WINDOWS], true)) {
            throw new RuntimeException('This script only supports macOS and Windows systems.');
        }

        $this->nCores = $nCores ?? $this->detectNCores();
    }

    private function detectNCores(): int
    {
        $process = $this->os === self::OS_DARWIN
            ? new Process(['sysctl', '-n', 'hw.ncpu'])
            : new Process(['powershell', '-Command', '(Get-CimInstance -ClassName Win32_Processor).NumberOfCores']);

        try {
            $process->mustRun();
        } catch (ProcessFailedException) {
            return 1; // Fallback
        }

        return max(1, (int) trim($process->getOutput()));
    }

    public function isWindows(): bool
    {
        return $this->os === self::OS_WINDOWS;
    }

    /**
     * Find a tool in the system PATH.
     *
     * @throws RuntimeException If the tool is not found.
     */
    public function findTool(string $tool): string
    {
        $which   = $this->isWindows() ? 'where.exe' : 'which';
        $process = new Process([$which, $tool]);
        $process->mustRun();

        $result = trim($process->getOutput());

        // On Windows, 'where' returns multiple lines, take the first
        if ($this->isWindows()) {
            $lines  = explode("\n", $result);
            $result = trim($lines[0]);
        }

        if ($result === '') {
            throw new RuntimeException(sprintf('Required tool not found: %s', $tool));
        }

        return $result;
    }
}
