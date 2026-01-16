<?php

declare(strict_types=1);

namespace App\Tests\Unit\Video\Domain;

use App\Video\Domain\ProcessExecutor;
use App\Video\Domain\ProcessResult;

use function file_put_contents;
use function max;
use function preg_match;
use function str_contains;
use function str_repeat;
use function str_starts_with;
use function substr;
use function sys_get_temp_dir;

use const DIRECTORY_SEPARATOR;

final class InMemoryProcessExecutor implements ProcessExecutor
{
    /** @var array<string, int> Map of file path patterns to sizes */
    private array $fileSizes;

    /** @var array<string, int> Actual file sizes written (for tracking vfs files) */
    private array $actualSizes = [];

    /**
     * @param array<string, ProcessResult> $commandResults Map of command patterns to results
     * @param array<string, int>           $fileSizes      Map of file path patterns to sizes (in bytes)
     */
    public function __construct(private array $commandResults = [], array $fileSizes = [])
    {
        $this->fileSizes = $fileSizes;
    }

    public function execute(string $command, callable|null $lineCallback = null): ProcessResult
    {
        $this->createFilesFromCommand($command);

        $result = $this->findResult($command);

        if ($lineCallback !== null) {
            foreach ($result->output as $line) {
                if ($line === '') {
                    continue;
                }

                $lineCallback($line);
            }
        }

        return $result;
    }

    private function createFilesFromCommand(string $command): void
    {
        // Extract file path from redirect patterns (e.g., "> /path/to/file.mp4")
        if (! preg_match('/>\s*([^\s]+)/', $command, $matches)) {
            return;
        }

        $filePath = $matches[1];
        // On Windows, replace /tmp with actual temp directory
        if (str_starts_with($filePath, '/tmp/')) {
            $filePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . substr($filePath, 5);
        }

        $size = $this->getFileSize($filePath);
        if ($size <= 0) {
            return;
        }

        if (str_starts_with($filePath, 'vfs://')) {
            file_put_contents($filePath, str_repeat('x', $size));
            $this->actualSizes[$filePath] = $size;

            return;
        }

        if (! str_contains($filePath, sys_get_temp_dir())) {
            return;
        }

        file_put_contents($filePath, str_repeat('x', $size));
        $this->actualSizes[$filePath] = $size;
    }

    private function getFileSize(string $filePath): int
    {
        if (isset($this->fileSizes[$filePath])) {
            return $this->fileSizes[$filePath];
        }

        foreach ($this->fileSizes as $pattern => $size) {
            if (str_contains($filePath, $pattern)) {
                return $size;
            }
        }

        if (str_contains($filePath, sys_get_temp_dir())) {
            if (! empty($this->actualSizes)) {
                return (int) max($this->actualSizes);
            }

            return 5;
        }

        return 0;
    }

    private function findResult(string $command): ProcessResult
    {
        if (isset($this->commandResults[$command])) {
            return $this->commandResults[$command];
        }

        foreach ($this->commandResults as $key => $result) {
            if (str_contains($command, $key)) {
                return $result;
            }
        }

        return new ProcessResult(0, []);
    }
}
