<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use RuntimeException;

interface Platform
{
    /**
     * Find a tool in the system PATH.
     *
     * @throws RuntimeException If the tool is not found.
     */
    public function findTool(string $tool): string;

    public function isWindows(): bool;

    public function nCores(): int;
}
