<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure;

use App\Shared\Domain\ProcessExecutor;
use App\Shared\Domain\ProcessResult;
use Symfony\Component\Process\Process;

use function explode;
use function trim;

final readonly class RealProcessExecutor implements ProcessExecutor
{
    public function execute(string $command, callable|null $lineCallback = null): ProcessResult
    {
        $process = Process::fromShellCommandline($command);
        $output  = [];

        $process->run(static function ($type, $buffer) use (&$output, $lineCallback): void {
            if ($type !== Process::OUT) {
                return;
            }

            $lines = explode("\n", trim($buffer));
            foreach ($lines as $line) {
                if ($line === '') {
                    continue;
                }

                $output[] = $line;
                if ($lineCallback === null) {
                    continue;
                }

                $lineCallback($line);
            }
        });

        $exitCode = $process->getExitCode();
        if ($exitCode === null) {
            $exitCode = -1;
        }

        return new ProcessResult($exitCode, $output);
    }
}
