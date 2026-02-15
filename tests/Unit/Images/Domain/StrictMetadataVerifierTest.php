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

    public function test_diffs_ignores_gps_altitude_difference_when_numeric_values_match_with_rounding_noise(): void
    {
        $verifier = new StrictMetadataVerifier();

        $source = ['GPS:GPSAltitude' => '123.456 m Above Sea Level'];
        $dest   = ['GPS:GPSAltitude' => '123.46 m Above Sea Level'];

        self::assertSame([], $verifier->diffs($source, $dest));
    }

    public function test_diffs_ignores_gps_horizontal_error_difference_when_numeric_values_match_with_rounding_noise(): void
    {
        $verifier = new StrictMetadataVerifier();

        $source = ['GPS:GPSHPositioningError' => '5.004 m'];
        $dest   = ['GPS:GPSHPositioningError' => '5.00 m'];

        self::assertSame([], $verifier->diffs($source, $dest));
    }

    public function test_diffs_flags_gps_altitude_difference_when_numeric_delta_is_meaningful(): void
    {
        $verifier = new StrictMetadataVerifier();

        $source = ['GPS:GPSAltitude' => '123.4 m Above Sea Level'];
        $dest   = ['GPS:GPSAltitude' => '124.0 m Above Sea Level'];

        self::assertSame(
            ['Tag differs: GPS:GPSAltitude'],
            $verifier->diffs($source, $dest),
        );
    }

    public function test_diffs_flags_missing_gps_tags_when_source_has_values_even_if_quicktime_coordinates_exist(): void
    {
        $verifier = new StrictMetadataVerifier();

        $source = [
            'GPS:GPSLatitudeRef' => 'N',
            'GPS:GPSLatitude' => '56 deg 57\' 34.66" N',
            'GPS:GPSLongitudeRef' => 'E',
            'GPS:GPSLongitude' => '24 deg 6\' 45.80" E',
            'GPS:GPSAltitude' => '12.34 m Above Sea Level',
        ];
        $dest   = ['QuickTime:GPSCoordinates' => '56.9000000, 24.1000000, 12.34'];

        self::assertSame(
            [
                'Missing tag in destination: GPS:GPSLatitudeRef',
                'Missing tag in destination: GPS:GPSLatitude',
                'Missing tag in destination: GPS:GPSLongitudeRef',
                'Missing tag in destination: GPS:GPSLongitude',
                'Missing tag in destination: GPS:GPSAltitude',
            ],
            $verifier->diffs($source, $dest),
        );
    }

    public function test_diffs_ignores_missing_gps_tags_when_source_values_are_only_placeholders(): void
    {
        $verifier = new StrictMetadataVerifier();

        $source = [
            'GPS:GPSLatitudeRef' => 'Unknown ()',
            'GPS:GPSLatitude' => '',
            'GPS:GPSLongitudeRef' => 'Unknown ()',
            'GPS:GPSLongitude' => '',
            'GPS:GPSAltitude' => 'undef',
        ];
        $dest   = [];

        self::assertSame([], $verifier->diffs($source, $dest));
    }

    public function test_diffs_ignores_missing_exif_colorspace_when_equivalent_projection_exists(): void
    {
        $verifier = new StrictMetadataVerifier();

        $source = ['ExifIFD:ColorSpace' => 'sRGB'];
        $dest   = ['EXIF:ColorSpace' => 'sRGB'];

        self::assertSame([], $verifier->diffs($source, $dest));
    }

    public function test_diffs_ignores_missing_exif_colorspace_when_numeric_projection_matches(): void
    {
        $verifier = new StrictMetadataVerifier();

        $source = ['ExifIFD:ColorSpace' => 'sRGB'];
        $dest   = ['EXIF:ColorSpace' => '1'];

        self::assertSame([], $verifier->diffs($source, $dest));
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
        yield 'ExifIFD image width missing' => ['ExifIFD:ImageWidth', false];
        yield 'ExifIFD image height missing' => ['ExifIFD:ImageHeight', false];

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
        yield 'Samsung trailer tag missing' => ['Samsung:SamsungTrailer_0x0d01', false];
        yield 'MakerUnknown private tag missing' => ['MakerUnknown:Unknown_0x0000', false];
        yield 'YCbCr positioning differs' => ['IFD0:YCbCrPositioning', true];
        yield 'Compressed bits per pixel missing' => ['ExifIFD:CompressedBitsPerPixel', false];
        yield 'Brightness value differs' => ['ExifIFD:BrightnessValue', true];
        yield 'Sony preview image missing' => ['Sony:PreviewImage', false];
        yield 'IFD0 thumbnail offset differs' => ['IFD0:ThumbnailOffset', true];
        yield 'IFD0 thumbnail length differs' => ['IFD0:ThumbnailLength', true];
        yield 'IFD0 thumbnail image differs' => ['IFD0:ThumbnailImage', true];
        yield 'Components configuration differs' => ['ExifIFD:ComponentsConfiguration', true];
        yield 'Adobe Camera Raw mask dabs missing' => ['XMP-crs:MaskGroupBasedCorrMaskMasksDabs', false];
        yield 'Adobe Camera Raw mask what missing' => ['XMP-crs:MaskGroupBasedCorrMaskMasksWhat', false];
        yield 'Adobe Camera Raw mask active missing' => ['XMP-crs:MaskGroupBasedCorrMaskMasksMaskActive', false];
        yield 'Adobe Camera Raw mask sync id missing' => ['XMP-crs:MaskGroupBasedCorrMaskMasksMaskSyncID', false];
        yield 'Adobe Camera Raw local grain missing' => ['XMP-crs:MaskGroupBasedCorrLocalGrain', false];
        yield 'Adobe Camera Raw local corrected depth missing' => ['XMP-crs:MaskGroupBasedCorrLocalCorrectedDepth', false];
        yield 'Adobe Camera Raw mask error reason missing' => ['XMP-crs:MaskGroupBasedCorrMaskErrorReason', false];
        yield 'Adobe Camera Raw retouch heal version missing' => ['XMP-crs:RetouchAreaHealVersion', false];
        yield 'Adobe Camera Raw retouch blend mode missing' => ['XMP-crs:RetouchAreaBlendMode', false];
        yield 'Photoshop camera profile vignette x center missing' => ['XMP-photoshop:CameraProfilesPerspectiveModelVignetteModelImageXCenter', false];
        yield 'Photoshop camera profile vignette piecewise missing' => ['XMP-photoshop:CameraProfilesPerspectiveModelVignetteModelVignetteModelPiecewiseParam', false];
        yield 'Alien Exposure virtual paths missing' => ['XMP-alienexposure:Virtualpaths', false];
        yield 'XMP TIFF image width missing' => ['XMP-tiff:ImageWidth', false];
        yield 'XMP TIFF image height missing' => ['XMP-tiff:ImageHeight', false];
        yield 'XMP extended packet marker missing' => ['XMP-xmpNote:HasExtendedXMP', false];
        yield 'XMP EXIF exposure compensation differs' => ['XMP-exif:ExposureCompensation', true];
    }
}
