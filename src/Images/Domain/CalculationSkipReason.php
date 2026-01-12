<?php

declare(strict_types=1);

namespace App\Images\Domain;

enum CalculationSkipReason: string
{
    case AvifNotSmaller     = 'AVIF not smaller than original';
    case QualityNotAchieved = 'could not achieve quality threshold';
}
