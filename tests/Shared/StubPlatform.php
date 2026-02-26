<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Shared\Domain\Platform;
use RuntimeException;

final class StubPlatform implements Platform
{
    public bool $isWindows = false;

    public bool $isLinux = false;

    public bool $isDarwin = false;

    public int $cores = 4;

    /** @var array<string, string> name: path */
    private array $tools = [];

    public function setTool(string $name, string $path): void
    {
        $this->tools[$name] = $path;
    }

    public function findTool(string $tool): string
    {
        return $this->tools[$tool] ?? throw new RuntimeException('Tool not found: ' . $tool);
    }

    public function isWindows(): bool
    {
        return $this->isWindows;
    }

    public function isLinux(): bool
    {
        return $this->isLinux;
    }

    public function isDarwin(): bool
    {
        return $this->isDarwin;
    }

    public function nCores(): int
    {
        return $this->cores;
    }
}
