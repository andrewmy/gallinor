<?php

declare(strict_types=1);

namespace App\Tests\Unit\Images\Domain;

use App\Images\Domain\ImageFile;
use App\Tests\Unit\FsTestCase;
use org\bovigo\vfs\vfsStream;

final class ImageFileTest extends FsTestCase
{
    public function test_optimized_path_replaces_extension_with_avif(): void
    {
        $image = new ImageFile('/path/to/photo.jpg', 1_000_000);

        self::assertSame('/path/to/photo.avif', $image->optimizedPath());
    }

    public function test_optimized_path_handles_nested_directories(): void
    {
        $image = new ImageFile('/deep/nested/path/to/image.jpg', 500_000);

        self::assertSame('/deep/nested/path/to/image.avif', $image->optimizedPath());
    }

    public function test_optimized_path_handles_jpeg_extension(): void
    {
        $image = new ImageFile('/path/to/photo.jpeg', 2_000_000);

        self::assertSame('/path/to/photo.avif', $image->optimizedPath());
    }

    public function test_optimized_path_handles_files_with_dots_in_name(): void
    {
        $image = new ImageFile('/path/to/photo.2024.edit.jpg', 1_500_000);

        self::assertSame('/path/to/photo.2024.edit.avif', $image->optimizedPath());
    }

    public function test_filename_returns_basename(): void
    {
        $image = new ImageFile('/path/to/photo.jpg', 1_000_000);

        self::assertSame('photo.jpg', $image->filename());
    }

    public function test_filename_returns_basename_with_nested_path(): void
    {
        $image = new ImageFile('/deep/nested/path/to/image.jpg', 500_000);

        self::assertSame('image.jpg', $image->filename());
    }

    public function test_all_properties_are_accessible(): void
    {
        $image = new ImageFile(
            path: '/path/to/photo.jpg',
            size: 1_500_000,
            isPortraitOrLivePhoto: true,
        );

        self::assertSame('/path/to/photo.jpg', $image->path);
        self::assertSame(1_500_000, $image->size);
        self::assertTrue($image->isPortraitOrLivePhoto);
    }

    public function test_is_portrait_or_live_photo_defaults_to_false(): void
    {
        $image = new ImageFile(
            path: '/path/to/photo.jpg',
            size: 1_000_000,
        );

        self::assertFalse($image->isPortraitOrLivePhoto);
    }

    public function test_has_optimized_returns_false_when_avif_does_not_exist(): void
    {
        vfsStream::newFile('photo.jpg')->at($this->root);

        $image = new ImageFile($this->vfsUrl('photo.jpg'), 1_000_000);

        self::assertFalse($image->hasOptimized());
    }

    public function test_has_optimized_returns_true_when_avif_exists(): void
    {
        vfsStream::newFile('photo.jpg')->at($this->root);
        vfsStream::newFile('photo.avif')->at($this->root);

        $image = new ImageFile($this->vfsUrl('photo.jpg'), 1_000_000);

        self::assertTrue($image->hasOptimized());
    }
}
