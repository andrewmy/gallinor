<?php

declare(strict_types=1);

namespace App\Images\Domain;

use function array_key_exists;
use function sprintf;
use function strpos;
use function substr;

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
        // Vendor/private maker-note projection tags are unstable across container rewrite.
        'MakerUnknown' => true,
        // Samsung trailer blocks are proprietary metadata payloads and non-portable across container rewrite.
        'Samsung' => true,
    ];

    private const string SHUTTER_SPEED_TAG                        = 'ExifIFD:ShutterSpeedValue';
    private const string EXPOSURE_TIME_TAG                        = 'ExifIFD:ExposureTime';
    private const string ACR_LOCAL_CORR_PREFIX                    = 'XMP-crs:MaskGroupBasedCorr';
    private const string ACR_RETOUCH_AREA_PREFIX                  = 'XMP-crs:RetouchArea';
    private const string PHOTOSHOP_CAMERA_PROFILE_VIGNETTE_PREFIX = 'XMP-photoshop:CameraProfilesPerspectiveModelVignetteModel';
    private const string ALIEN_EXPOSURE_XMP_PREFIX                = 'XMP-alienexposure:';

    private const array IGNORED_TAGS = [
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
        // Adobe Camera Raw mask metadata: editing instructions for gradient-based corrections
        // stored by Lightroom/ACR. These are non-portable editing parameters, not capture metadata.
        'XMP-crs:MaskGroupBasedCorrMaskMasksDabs' => true,
        'XMP-crs:MaskGroupBasedCorrMaskMasksWhat' => true,
        // XMP TIFF dimensions are projection metadata and can be dropped/recomputed during container rewrite.
        'XMP-tiff:ImageWidth' => true,
        'XMP-tiff:ImageHeight' => true,
    ];

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

            if (! array_key_exists($tag, $dest)) {
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
