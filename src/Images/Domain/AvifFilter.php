<?php

declare(strict_types=1);

namespace App\Images\Domain;

enum AvifFilter
{
    case OnlyWith;
    case OnlyWithout;
}
