<?php

declare(strict_types=1);

namespace App\Video\Domain;

use App\Shared\Domain\Platform;
use App\Video\Infrastructure\FfmpegEncoder;

final readonly class EncoderFactory
{
    public function __construct(
        private Platform $platform,
    ) {
    }

    public function create(bool $useCpu): Encoder
    {
        return new FfmpegEncoder($useCpu, $this->platform);
    }
}
