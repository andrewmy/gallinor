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

    public function test_diffs_ignores_shutter_speed_difference_when_exposure_time_matches(): void
    {
        $verifier = new StrictMetadataVerifier();

        $source = [
            'ExifIFD:ShutterSpeedValue' => '1/999963365',
            'ExifIFD:ExposureTime' => '1/33',
        ];
        $dest   = [
            'ExifIFD:ShutterSpeedValue' => '1/999963296',
            'ExifIFD:ExposureTime' => '1/33',
        ];

        self::assertSame([], $verifier->diffs($source, $dest));
    }

    public function test_diffs_flags_shutter_speed_difference_when_exposure_time_differs(): void
    {
        $verifier = new StrictMetadataVerifier();

        $source = [
            'ExifIFD:ShutterSpeedValue' => '1/999963365',
            'ExifIFD:ExposureTime' => '1/33',
        ];
        $dest   = [
            'ExifIFD:ShutterSpeedValue' => '1/999963296',
            'ExifIFD:ExposureTime' => '1/30',
        ];

        self::assertSame(
            ['Tag differs: ExifIFD:ShutterSpeedValue', 'Tag differs: ExifIFD:ExposureTime'],
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

        yield 'ExifIFD orientation differs' => ['ExifIFD:Orientation', true];

        yield 'IFD0 compression missing' => ['IFD0:Compression', false];

        yield 'ExifIFD user comment differs' => ['ExifIFD:UserComment', true];

        yield 'ExifIFD scene type missing' => ['ExifIFD:SceneType', false];

        yield 'InteropIFD missing' => ['InteropIFD:InteropIndex', false];

        yield 'XMP toolkit differs' => ['XMP-x:XMPToolkit', true];

        yield 'Focal plane resolution differs' => ['ExifIFD:FocalPlaneXResolution', true];

        yield 'Unknown ExifIFD private tag missing' => ['ExifIFD:Exif_0x9aaa', false];
        yield 'MakerNote unknown text missing' => ['ExifIFD:MakerNoteUnknownText', false];
        yield 'JSON vendor tag missing' => ['JSON:Mirror', false];
        yield 'MiCamera XMP tag missing' => ['XMP-MiCamera:XMPMeta', false];
        yield 'MakerUnknown private tag missing' => ['MakerUnknown:Unknown_0x0000', false];
        yield 'YCbCr positioning differs' => ['IFD0:YCbCrPositioning', true];
        yield 'Compressed bits per pixel missing' => ['ExifIFD:CompressedBitsPerPixel', false];
        yield 'Sony preview image missing' => ['Sony:PreviewImage', false];
        yield 'IFD0 thumbnail offset differs' => ['IFD0:ThumbnailOffset', true];
        yield 'IFD0 thumbnail length differs' => ['IFD0:ThumbnailLength', true];
        yield 'IFD0 thumbnail image differs' => ['IFD0:ThumbnailImage', true];
        yield 'Components configuration differs' => ['ExifIFD:ComponentsConfiguration', true];
        yield 'Adobe Camera Raw mask dabs missing' => ['XMP-crs:MaskGroupBasedCorrMaskMasksDabs', false];
        yield 'Adobe Camera Raw mask what missing' => ['XMP-crs:MaskGroupBasedCorrMaskMasksWhat', false];
        yield 'Adobe Camera Raw mask active missing' => ['XMP-crs:MaskGroupBasedCorrMaskMasksMaskActive', false];
        yield 'Adobe Camera Raw mask sync id missing' => ['XMP-crs:MaskGroupBasedCorrMaskMasksMaskSyncID', false];
    }
}
