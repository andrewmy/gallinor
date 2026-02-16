<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Shared\Domain\ProcessExecutor;
use App\Shared\Domain\ProcessResult;

use function file_put_contents;
use function max;
use function preg_match;
use function str_contains;
use function str_repeat;
use function str_starts_with;
use function strlen;
use function sys_get_temp_dir;
use function trim;

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
        $this->createTarArtifactsFromCommand($command);

        $filePath = null;

        if (preg_match('/>\s*([^\s]+)/', $command, $matches) === 1) {
            $filePath = $matches[1];
        }

        if ($filePath === null) {
            return;
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

    private function createTarArtifactsFromCommand(string $command): void
    {
        if (preg_match('/\btar\s+-cf\s+([^\s]+)\s+-C\s+/', $command, $matches) === 1) {
            $tarPath = trim($matches[1], '\'"');
            $size    = $this->getFileSize($tarPath);
            if ($size <= 0) {
                $size = 5;
            }

            $this->writeSizedFile($tarPath, $size);

            return;
        }

        if (preg_match('/\bxz\b/', $command) !== 1) {
            return;
        }

        if (preg_match('/\s([^\s]+)\s+2>&1$/', $command, $matches) !== 1) {
            return;
        }

        $tarPath       = trim($matches[1], '\'"');
        $compressedTar = $tarPath . '.xz';
        $size          = $this->getFileSize($compressedTar);
        if ($size <= 0) {
            $size = max(1, $this->getFileSize($tarPath));
        }

        $this->writeSizedFile($compressedTar, $size);
    }

    private function writeSizedFile(string $path, int $size): void
    {
        if ($size <= 0) {
            return;
        }

        file_put_contents($path, str_repeat('x', $size));
        $this->actualSizes[$path] = $size;
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

        $bestMatchKey = null;
        $bestMatchLen = -1;

        foreach ($this->commandResults as $key => $result) {
            if (! str_contains($command, $key)) {
                continue;
            }

            $candidateLen = strlen($key);
            if ($candidateLen < $bestMatchLen) {
                continue;
            }

            $bestMatchKey = $key;
            $bestMatchLen = $candidateLen;
        }

        if ($bestMatchKey !== null) {
            return $this->commandResults[$bestMatchKey];
        }

        return new ProcessResult(0, []);
    }
}
