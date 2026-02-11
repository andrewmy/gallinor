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
    ];

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
        // Maker/lens derived calibration numbers are often rewritten with different formatting/precision.
        'ExifIFD:FocalPlaneXResolution' => true,
        'ExifIFD:FocalPlaneYResolution' => true,
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

        $pos = strpos($tag, ':');
        if ($pos === false) {
            return false;
        }

        $group = substr($tag, 0, $pos);

        return isset(self::IGNORED_GROUPS[$group]);
    }
}
