<?php

declare(strict_types=1);

namespace App\Video\Domain;

use App\Shared\Domain\Platform;

final readonly class FfmpegFactory
{
    public function __construct(
        private Platform $platform,
    ) {
    }

    public function create(bool $useCpu): Ffmpeg
    {
        return new Ffmpeg($useCpu, $this->platform);
    }
}
