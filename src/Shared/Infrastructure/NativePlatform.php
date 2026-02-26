<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure;

use App\Shared\Domain\Platform;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

use function explode;
use function in_array;
use function max;
use function sprintf;
use function trim;

use const PHP_OS_FAMILY;

final readonly class NativePlatform implements Platform
{
    private const string OS_DARWIN  = 'Darwin';
    private const string OS_LINUX   = 'Linux';
    private const string OS_WINDOWS = 'Windows';

    public string $os;
    public int $nCores;

    public function __construct()
    {
        $this->os = PHP_OS_FAMILY;
        if (! in_array($this->os, [self::OS_DARWIN, self::OS_LINUX, self::OS_WINDOWS], true)) {
            throw new RuntimeException('Unsupported OS: ' . $this->os);
        }

        $this->nCores = $this->detectNCores();
    }

    private function detectNCores(): int
    {
        $process = match ($this->os) {
            self::OS_DARWIN => new Process(['sysctl', '-n', 'hw.ncpu']),
            self::OS_LINUX => new Process(['nproc']),
            self::OS_WINDOWS => new Process(['powershell', '-Command', '(Get-CimInstance -ClassName Win32_Processor).NumberOfCores']),
        };

        try {
            $process->mustRun();
        } catch (ProcessFailedException) {
            return 1;
        }

        return max(1, (int) trim($process->getOutput()));
    }

    public function isWindows(): bool
    {
        return $this->os === self::OS_WINDOWS;
    }

    public function isLinux(): bool
    {
        return $this->os === self::OS_LINUX;
    }

    public function isDarwin(): bool
    {
        return $this->os === self::OS_DARWIN;
    }

    public function nCores(): int
    {
        return $this->nCores;
    }

    public function findTool(string $tool): string
    {
        if ($this->isWindows() && $tool === 'ssimulacra2') {
            $tool = 'ssimulacra2_rs';
        }

        $which   = $this->isWindows() ? 'where.exe' : 'which';
        $process = new Process([$which, $tool]);
        $process->mustRun();

        $result = trim($process->getOutput());

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
