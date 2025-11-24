<?php

declare(strict_types=1);

namespace App\Domain;

enum VideoEncoder: string
{
    case Apple  = 'hevc_videotoolbox';
    case Nvidia = 'hevc_nvenc';
    case Cpu    = 'libx265';
}
