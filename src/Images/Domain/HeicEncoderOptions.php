<?php

declare(strict_types=1);

namespace App\Images\Domain;

final readonly class HeicEncoderOptions
{
    /** @param array<string, scalar> $x265Params */
    public function __construct(
        public int $quality,
        public array $x265Params = [],
    ) {
    }
}
