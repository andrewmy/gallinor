<?php

declare(strict_types=1);

namespace App\Tests\Unit\Images\Domain;

use App\Images\Domain\ImageFormat;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ImageFormatTest extends TestCase
{
    public function test_from_cli_accepts_case_insensitive_values(): void
    {
        self::assertSame(ImageFormat::Heic, ImageFormat::fromCli('HEIC'));
    }

    public function test_from_cli_throws_on_invalid_value(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ImageFormat::fromCli('webp');
    }
}
