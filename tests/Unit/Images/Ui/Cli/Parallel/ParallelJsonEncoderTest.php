<?php

declare(strict_types=1);

namespace App\Tests\Unit\Images\Ui\Cli\Parallel;

use App\Images\Ui\Cli\Parallel\ParallelJsonEncoder;
use PHPUnit\Framework\TestCase;

use function json_decode;

use const JSON_THROW_ON_ERROR;

final class ParallelJsonEncoderTest extends TestCase
{
    public function test_encode_substitutes_invalid_utf8_strings(): void
    {
        $invalidUtf8 = "\xC3\x28";

        $encoded = ParallelJsonEncoder::encode(['error' => $invalidUtf8]);
        $decoded = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertArrayHasKey('error', $decoded);
        self::assertIsString($decoded['error']);
        self::assertStringContainsString("\u{FFFD}", $decoded['error']);
    }

    public function test_encode_keeps_valid_utf8_strings_unchanged(): void
    {
        $encoded = ParallelJsonEncoder::encode(['phase' => 'score']);
        $decoded = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertSame('score', $decoded['phase']);
    }
}
