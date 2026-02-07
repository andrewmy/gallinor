<?php

declare(strict_types=1);

namespace App\Images\Domain;

enum OptimizedFilter
{
    case OnlyWith;
    case OnlyWithout;
}
