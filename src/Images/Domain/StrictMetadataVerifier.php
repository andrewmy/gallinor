<?php

declare(strict_types=1);

namespace App\Images\Domain;

use function abs;
use function array_key_exists;
use function in_array;
use function preg_match;
use function sprintf;
use function str_ends_with;
use function str_replace;
use function strpos;
use function strtolower;
use function substr;
use function trim;

final readonly class StrictMetadataVerifier
{
    private const array IGNORED_GROUPS = [
        'File' => true,
        'ExifTool' => true,
        'Composite' => true,
        'System' => true,
        'QuickTime' => true,
        'IFD1' => true,
        'InteropIFD' => true,
        // Vendor JSON payload extracted by ExifTool is not preserved across container re-encode.
        'JSON' => true,
        // Xiaomi vendor XMP block is not portable across AVIF/HEIC rewrite.
        'XMP-MiCamera' => true,
        // XMP packet bookkeeping block (extended payload references) is rewritten/dropped on metadata rewrite.
        'XMP-xmpNote' => true,
        // Vendor/private maker-note projection tags are unstable across container rewrite.
        'MakerUnknown' => true,
        // Samsung trailer blocks are proprietary metadata payloads and non-portable across container rewrite.
        'Samsung' => true,
    ];

    private const string SHUTTER_SPEED_TAG                        = 'ExifIFD:ShutterSpeedValue';
    private const string EXPOSURE_TIME_TAG                        = 'ExifIFD:ExposureTime';
    private const string COLOR_SPACE_TAG                          = 'ExifIFD:ColorSpace';
    private const string GPS_ALTITUDE_TAG                         = 'GPS:GPSAltitude';
    private const string GPS_LATITUDE_TAG                         = 'GPS:GPSLatitude';
    private const string GPS_LATITUDE_REF_TAG                     = 'GPS:GPSLatitudeRef';
    private const string GPS_LONGITUDE_TAG                        = 'GPS:GPSLongitude';
    private const string GPS_LONGITUDE_REF_TAG                    = 'GPS:GPSLongitudeRef';
    private const string GPS_H_POSITIONING_ERROR_TAG              = 'GPS:GPSHPositioningError';
    private const string ACR_LOCAL_CORR_PREFIX                    = 'XMP-crs:MaskGroupBasedCorr';
    private const string ACR_RETOUCH_AREA_PREFIX                  = 'XMP-crs:RetouchArea';
    private const string ACR_LOOK_PARAMETERS_PREFIX               = 'XMP-crs:LookParameters';
    private const string PHOTOSHOP_CAMERA_PROFILE_VIGNETTE_PREFIX = 'XMP-photoshop:CameraProfilesPerspectiveModelVignetteModel';
    private const string ALIEN_EXPOSURE_XMP_PREFIX                = 'XMP-alienexposure:';

    private const array IGNORED_TAGS                    = [
        'SourceFile' => true,
        // File format/container details: must differ across AVIF→HEIC / JPEG→HEIC.
        'IFD0:Compression' => true,
        'EXIF:Orientation' => true, // we force this to 1 after baking rotation
        'IFD0:Orientation' => true,
        'ExifIFD:Orientation' => true,
        // EXIF fields that are commonly missing or rewritten/normalized when re-encoding
        // or when ExifTool rewrites metadata into a different container.
        'ExifIFD:SceneType' => true,
        'ExifIFD:UserComment' => true,
        // Compression/layout descriptors can differ after transcode even when visual content is intact.
        'ExifIFD:CompressedBitsPerPixel' => true,
        'ExifIFD:ComponentsConfiguration' => true,
        // BrightnessValue is a derived exposure metric and can be re-normalized by metadata rewrite.
        'ExifIFD:BrightnessValue' => true,
        // Maker/lens derived calibration numbers are often rewritten with different formatting/precision.
        'ExifIFD:FocalPlaneXResolution' => true,
        'ExifIFD:FocalPlaneYResolution' => true,
        'ExifIFD:ImageWidth' => true,
        'ExifIFD:ImageHeight' => true,
        'ExifIFD:ExifImageWidth' => true,
        'ExifIFD:ExifImageHeight' => true,
        'EXIF:ExifImageWidth' => true,
        'EXIF:ExifImageHeight' => true,
        'EXIF:ImageWidth' => true,
        'EXIF:ImageHeight' => true,
        'IFD0:ImageWidth' => true,
        'IFD0:ImageHeight' => true,
        // Thumbnail payload and byte offsets are re-generated and inherently unstable.
        'IFD0:ThumbnailOffset' => true,
        'IFD0:ThumbnailLength' => true,
        'IFD0:ThumbnailImage' => true,
        // YCbCr positioning is encoding-layout metadata, not capture metadata.
        'IFD0:YCbCrPositioning' => true,
        // Vendor preview blob is not preserved in AVIF/HEIC rewrite path.
        'Sony:PreviewImage' => true,
        // ExifTool/XMP writer signature: expected to change when rewriting metadata.
        'XMP-x:XMPToolkit' => true,
        // XMP projection of EXIF exposure compensation may be rewritten independently of core EXIF fields.
        'XMP-exif:ExposureCompensation' => true,
        // Adobe Camera Raw mask metadata: editing instructions for gradient-based corrections
        // stored by Lightroom/ACR. These are non-portable editing parameters, not capture metadata.
        'XMP-crs:MaskGroupBasedCorrMaskMasksDabs' => true,
        'XMP-crs:MaskGroupBasedCorrMaskMasksWhat' => true,
        // XMP TIFF dimensions are projection metadata and can be dropped/recomputed during container rewrite.
        'XMP-tiff:ImageWidth' => true,
        'XMP-tiff:ImageHeight' => true,
    ];
    private const float GPS_NUMERIC_EQ_TOLERANCE_METERS = 0.01;

    /**
     * @param array<string, string> $source
     * @param array<string, string> $dest
     *
     * @return list<string> List of human-readable diffs (empty means ok)
     */
    public function diffs(array $source, array $dest): array
    {
        $diffs = [];

        foreach ($source as $tag => $value) {
            if ($this->shouldIgnore($tag)) {
                continue;
            }

            if ($this->shouldIgnoreShutterSpeedDifference($tag, $value, $source, $dest)) {
                continue;
            }

            if ($this->shouldIgnoreGpsNumericDifference($tag, $value, $dest)) {
                continue;
            }

            if (! array_key_exists($tag, $dest)) {
                if ($this->shouldTreatMissingTagAsEquivalentProjection($tag, $source, $dest, $value)) {
                    continue;
                }

                $diffs[] = sprintf('Missing tag in destination: %s', $tag);
                continue;
            }

            if ((string) $dest[$tag] === (string) $value) {
                continue;
            }

            $diffs[] = sprintf('Tag differs: %s', $tag);
        }

        return $diffs;
    }

    private function shouldIgnore(string $tag): bool
    {
        if (isset(self::IGNORED_TAGS[$tag])) {
            return true;
        }

        // Unknown/private EXIF tags are represented as Exif_0xNNNN and are frequently dropped by container conversion.
        if (strpos($tag, 'ExifIFD:Exif_0x') === 0) {
            return true;
        }

        // ExifTool-generated MakerNote text projections are non-portable across AVIF/HEIC rewrites.
        if (strpos($tag, 'ExifIFD:MakerNoteUnknown') === 0) {
            return true;
        }

        // Adobe Camera Raw local adjustment payload is non-portable across container rewrites.
        if (strpos($tag, self::ACR_LOCAL_CORR_PREFIX) === 0) {
            return true;
        }

        // Adobe Camera Raw retouch-area payload is non-portable across container rewrites.
        if (strpos($tag, self::ACR_RETOUCH_AREA_PREFIX) === 0) {
            return true;
        }

        // Adobe Camera Raw look-table payload is non-portable across container rewrites.
        if (strpos($tag, self::ACR_LOOK_PARAMETERS_PREFIX) === 0) {
            return true;
        }

        // Photoshop camera-profile vignette calibration payload is non-portable across container rewrites.
        if (strpos($tag, self::PHOTOSHOP_CAMERA_PROFILE_VIGNETTE_PREFIX) === 0) {
            return true;
        }

        // Alien Exposure vendor XMP block stores editor-local state and is non-portable across container rewrites.
        if (strpos($tag, self::ALIEN_EXPOSURE_XMP_PREFIX) === 0) {
            return true;
        }

        $pos = strpos($tag, ':');
        if ($pos === false) {
            return false;
        }

        $group = substr($tag, 0, $pos);

        return isset(self::IGNORED_GROUPS[$group]);
    }

    /**
     * GPS altitude/accuracy tags may be rewritten with equivalent numeric values
     * (units/precision formatting) during metadata rewrite.
     *
     * @param array<string, string> $dest
     */
    private function shouldIgnoreGpsNumericDifference(string $tag, string $sourceValue, array $dest): bool
    {
        if ($tag !== self::GPS_ALTITUDE_TAG && $tag !== self::GPS_H_POSITIONING_ERROR_TAG) {
            return false;
        }

        if (! array_key_exists($tag, $dest)) {
            return false;
        }

        if ((string) $dest[$tag] === $sourceValue) {
            return false;
        }

        $sourceNumeric = $this->extractFirstNumericValue($sourceValue);
        $destNumeric   = $this->extractFirstNumericValue((string) $dest[$tag]);

        if ($sourceNumeric === null || $destNumeric === null) {
            return false;
        }

        return abs($sourceNumeric - $destNumeric) <= self::GPS_NUMERIC_EQ_TOLERANCE_METERS;
    }

    private function extractFirstNumericValue(string $value): float|null
    {
        if (preg_match('/[-+]?\d+(?:\.\d+)?(?:[eE][-+]?\d+)?/', $value, $matches) !== 1) {
            return null;
        }

        return (float) $matches[0];
    }

    /**
     * Some containers keep critical capture metadata in equivalent projection tags
     * (for example QuickTime/Keys GPS coordinates or EXIF-vs-XMP ColorSpace).
     *
     * @param array<string, string> $source
     * @param array<string, string> $dest
     */
    private function shouldTreatMissingTagAsEquivalentProjection(
        string $missingTag,
        array $source,
        array $dest,
        string $sourceValue,
    ): bool {
        return $this->hasEquivalentColorSpaceProjection($missingTag, $sourceValue, $dest)
            || $this->shouldIgnoreMissingGpsTagFromEmptySource($missingTag, $source);
    }

    /** @param array<string, string> $dest */
    private function hasEquivalentColorSpaceProjection(string $missingTag, string $sourceValue, array $dest): bool
    {
        if ($missingTag !== self::COLOR_SPACE_TAG) {
            return false;
        }

        $normalizedSource = $this->normalizeColorSpaceValue($sourceValue);

        foreach ($dest as $tag => $value) {
            if (! str_ends_with($tag, ':ColorSpace')) {
                continue;
            }

            if ($this->normalizeColorSpaceValue((string) $value) === $normalizedSource) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, string> $source */
    private function shouldIgnoreMissingGpsTagFromEmptySource(string $missingTag, array $source): bool
    {
        if (
            ! in_array($missingTag, [
                self::GPS_LATITUDE_REF_TAG,
                self::GPS_LATITUDE_TAG,
                self::GPS_LONGITUDE_REF_TAG,
                self::GPS_LONGITUDE_TAG,
                self::GPS_ALTITUDE_TAG,
            ], true)
        ) {
            return false;
        }

        $rawTokens = [
            (string) ($source[self::GPS_LATITUDE_REF_TAG] ?? ''),
            (string) ($source[self::GPS_LATITUDE_TAG] ?? ''),
            (string) ($source[self::GPS_LONGITUDE_REF_TAG] ?? ''),
            (string) ($source[self::GPS_LONGITUDE_TAG] ?? ''),
            (string) ($source[self::GPS_ALTITUDE_TAG] ?? ''),
        ];

        foreach ($rawTokens as $rawToken) {
            if (! $this->isGpsSourcePlaceholderValue($rawToken)) {
                return false;
            }
        }

        return true;
    }

    private function normalizeColorSpaceValue(string $value): string
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '1') {
            return 'srgb';
        }

        if ($normalized === '65535') {
            return 'uncalibrated';
        }

        if ($normalized === 's-rgb') {
            return 'srgb';
        }

        return str_replace(' ', '', $normalized);
    }

    private function isGpsSourcePlaceholderValue(string $value): bool
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return true;
        }

        return in_array($normalized, ['undef', 'unknown', 'unknown ()'], true);
    }

    /**
     * Some containers rewrite ShutterSpeedValue representation while preserving ExposureTime.
     * Treat this as equivalent capture metadata when ExposureTime remains identical.
     *
     * @param array<string, string> $source
     * @param array<string, string> $dest
     */
    private function shouldIgnoreShutterSpeedDifference(string $tag, string $sourceValue, array $source, array $dest): bool
    {
        if ($tag !== self::SHUTTER_SPEED_TAG) {
            return false;
        }

        if (! array_key_exists($tag, $dest)) {
            return false;
        }

        if ((string) $dest[$tag] === $sourceValue) {
            return false;
        }

        if (! array_key_exists(self::EXPOSURE_TIME_TAG, $source) || ! array_key_exists(self::EXPOSURE_TIME_TAG, $dest)) {
            return false;
        }

        return (string) $source[self::EXPOSURE_TIME_TAG] === (string) $dest[self::EXPOSURE_TIME_TAG];
    }
}
