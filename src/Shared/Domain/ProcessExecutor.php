<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/** Executes external processes and captures their output */
interface ProcessExecutor
{
    /** @param callable(string): void|null $lineCallback Called with each line of output */
    public function execute(string $command, callable|null $lineCallback = null): ProcessResult;
}
