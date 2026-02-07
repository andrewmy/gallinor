<?php

declare(strict_types=1);

namespace App\Tests\Unit\Images\Domain;

use App\Images\Domain\StrictMetadataVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StrictMetadataVerifierTest extends TestCase
{
    #[DataProvider('ignoredTagProvider')]
    public function test_diffs_ignores_tag(string $tag, bool $includeInDest): void
    {
        $verifier = new StrictMetadataVerifier();

        $source = [
            $tag => 'source-value',
            'EXIF:Make' => 'samsung', // control tag (should still match)
        ];

        $dest = ['EXIF:Make' => 'samsung'];

        if ($includeInDest) {
            $dest[$tag] = 'dest-value';
        }

        self::assertSame([], $verifier->diffs($source, $dest));
    }

    public function test_diffs_flags_missing_non_ignored_tags(): void
    {
        $verifier = new StrictMetadataVerifier();

        $source = ['EXIF:Make' => 'samsung'];

        $dest = [];

        self::assertSame(
            ['Missing tag in destination: EXIF:Make'],
            $verifier->diffs($source, $dest),
        );
    }

    /** @return iterable<array{string, bool}> */
    public static function ignoredTagProvider(): iterable
    {
        yield 'SourceFile differs' => ['SourceFile', true];
        yield 'SourceFile missing' => ['SourceFile', false];

        yield 'System differs' => ['System:FileName', true];
        yield 'System missing' => ['System:FileName', false];

        yield 'QuickTime differs' => ['QuickTime:MajorBrand', true];
        yield 'QuickTime missing' => ['QuickTime:MajorBrand', false];

        yield 'ExifIFD dimensions missing' => ['ExifIFD:ExifImageWidth', false];

        yield 'IFD1 thumbnail differs' => ['IFD1:ThumbnailOffset', true];
    }
}
