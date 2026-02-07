<?php

declare(strict_types=1);

namespace App\Tests\Unit\Images\Domain;

use App\Images\Domain\ImageFileCollector;
use App\Images\Domain\ImageFormat;
use App\Images\Domain\OptimizedFilter;
use App\Tests\Shared\FixedFilesystemScanner;
use App\Tests\Shared\StubExifMetadata;
use App\Tests\Unit\FsTestCase;
use org\bovigo\vfs\vfsStream;
use Symfony\Component\Console\Output\NullOutput;

final class ImageFileCollectorTest extends FsTestCase
{
    public function test_collect_from_directories_skips_portrait_and_live_photos(): void
    {
        $photosDir = vfsStream::newDirectory('photos')->at($this->root);

        $dir  = $this->vfsUrl('photos');
        $jpeg = $this->vfsUrl('photos/photo.jpg');

        vfsStream::newFile('photo.jpg')->withContent('jpeg')->at($photosDir);

        $exif = new StubExifMetadata([$jpeg => true]);

        $scanner   = new FixedFilesystemScanner([$jpeg]);
        $collector = new ImageFileCollector($scanner, $exif);
        $result    = $collector->collectFromDirectories(
            directories: [$dir],
            output: new NullOutput(),
            format: ImageFormat::Heic,
            filter: OptimizedFilter::OnlyWithout,
        );

        self::assertSame(1, $result->stats->jpegsFound);
        self::assertSame(1, $result->stats->jpegsSkipped);
        self::assertCount(0, $result->jpegs);
    }

    public function test_collect_from_directories_filters_only_without_optimized_for_format(): void
    {
        $photosDir = vfsStream::newDirectory('photos')->at($this->root);

        $dir  = $this->vfsUrl('photos');
        $jpeg = $this->vfsUrl('photos/photo.jpg');
        $heic = $this->vfsUrl('photos/photo.heic');

        vfsStream::newFile('photo.jpg')->withContent('jpeg')->at($photosDir);
        vfsStream::newFile('photo.heic')->withContent('heic')->at($photosDir);

        $exif = new StubExifMetadata();

        $scanner   = new FixedFilesystemScanner([$jpeg, $heic]);
        $collector = new ImageFileCollector($scanner, $exif);
        $result    = $collector->collectFromDirectories(
            directories: [$dir],
            output: new NullOutput(),
            format: ImageFormat::Heic,
            filter: OptimizedFilter::OnlyWithout,
        );

        self::assertSame(1, $result->stats->jpegsFound);
        self::assertSame(1, $result->stats->jpegsSkipped);
        self::assertCount(0, $result->jpegs);
    }

    public function test_collect_from_directories_filters_only_with_optimized_for_format(): void
    {
        $photosDir = vfsStream::newDirectory('photos')->at($this->root);

        $dir  = $this->vfsUrl('photos');
        $jpeg = $this->vfsUrl('photos/photo.jpg');
        $avif = $this->vfsUrl('photos/photo.avif');

        vfsStream::newFile('photo.jpg')->withContent('jpeg')->at($photosDir);
        vfsStream::newFile('photo.avif')->withContent('avif')->at($photosDir);

        $exif = new StubExifMetadata();

        $scanner   = new FixedFilesystemScanner([$jpeg, $avif]);
        $collector = new ImageFileCollector($scanner, $exif);
        $result    = $collector->collectFromDirectories(
            directories: [$dir],
            output: new NullOutput(),
            format: ImageFormat::Avif,
            filter: OptimizedFilter::OnlyWith,
        );

        self::assertSame(1, $result->stats->jpegsFound);
        self::assertSame(0, $result->stats->jpegsSkipped);
        self::assertCount(1, $result->jpegs);
    }
}
