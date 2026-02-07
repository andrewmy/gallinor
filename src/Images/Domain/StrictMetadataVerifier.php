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
    ];

    private const array IGNORED_TAGS = [
        'SourceFile' => true,
        'EXIF:Orientation' => true, // we force this to 1 after baking rotation
        'IFD0:Orientation' => true,
        'ExifIFD:Orientation' => true,
        'ExifIFD:ExifImageWidth' => true,
        'ExifIFD:ExifImageHeight' => true,
        'EXIF:ExifImageWidth' => true,
        'EXIF:ExifImageHeight' => true,
        'EXIF:ImageWidth' => true,
        'EXIF:ImageHeight' => true,
        'IFD0:ImageWidth' => true,
        'IFD0:ImageHeight' => true,
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

        $pos = strpos($tag, ':');
        if ($pos === false) {
            return false;
        }

        $group = substr($tag, 0, $pos);

        return isset(self::IGNORED_GROUPS[$group]);
    }
}
