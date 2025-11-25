<?php

declare(strict_types=1);

namespace App\Ui\Cli;

use function sprintf;

final class CliHelper
{
    public function link(string $path, string|null $label = null): string
    {
        $label ??= $path;

        return sprintf("\e]8;;file://%s\e\\%s\e]8;;\e\\", $path, $label);
    }
}
