<?php

declare(strict_types=1);

namespace App\Domain;

enum AvifFilter
{
    case OnlyWith;
    case OnlyWithout;
}
