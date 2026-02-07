<?php

declare(strict_types=1);

namespace App\Images\Domain;

use InvalidArgumentException;

use function strtolower;

enum ImageFormat: string
{
    case Avif = 'avif';
    case Heic = 'heic';

    public function extension(): string
    {
        return $this->value;
    }

    public function label(): string
    {
        return $this->value;
    }

    public static function fromCli(string $value): self
    {
        return match (strtolower($value)) {
            'avif' => self::Avif,
            'heic' => self::Heic,
            default => throw new InvalidArgumentException('Invalid format. Allowed: heic, avif.'),
        };
    }
}
