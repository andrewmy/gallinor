<?php

declare(strict_types=1);

namespace App\Images\Domain;

enum CalculationSkipReason: string
{
    case ReplacementNotSmaller = 'replacement not smaller than original';
    case QualityNotAchieved    = 'could not achieve quality threshold';
}
