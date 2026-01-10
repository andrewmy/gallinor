<?php

declare(strict_types=1);

namespace App\Domain;

use RuntimeException;

use function escapeshellarg;
use function explode;
use function in_array;
use function max;
use function shell_exec;
use function sprintf;
use function trim;

use const PHP_OS_FAMILY;

final readonly class Platform
{
    private const string OS_DARWIN  = 'Darwin';
    private const string OS_WINDOWS = 'Windows';

    public string $os;
    public int $nCores;

    public function __construct()
    {
        $this->os = PHP_OS_FAMILY;
        if (! in_array($this->os, [self::OS_DARWIN, self::OS_WINDOWS], true)) {
            throw new RuntimeException('This script only supports macOS and Windows systems.');
        }

        $this->nCores = max(1, match ($this->os) {
            self::OS_DARWIN => (int) shell_exec('sysctl -n hw.ncpu'),
            self::OS_WINDOWS => (int) shell_exec('powershell -Command "(Get-CimInstance -ClassName Win32_Processor).NumberOfCores"'),
        });
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
        $which  = $this->isWindows() ? 'where.exe' : 'which';
        $result = trim((string) shell_exec(sprintf('%s %s 2>/dev/null', $which, escapeshellarg($tool))));

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
