<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use function str_replace;

use const DIRECTORY_SEPARATOR;

trait PathNormalizer
{
    private function normalizePath(string $path): string
    {
        return str_replace(DIRECTORY_SEPARATOR, '/', $path);
    }
}
