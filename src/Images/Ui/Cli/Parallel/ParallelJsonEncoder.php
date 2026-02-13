<?php

declare(strict_types=1);

namespace App\Images\Ui\Cli\Parallel;

use function json_encode;

use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_THROW_ON_ERROR;

final readonly class ParallelJsonEncoder
{
    /** @param array<array-key, mixed> $payload */
    public static function encode(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
