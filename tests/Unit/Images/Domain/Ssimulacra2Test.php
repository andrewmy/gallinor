<?php

declare(strict_types=1);

namespace App\Tests\Unit\Images\Domain;

use App\Images\Domain\Ssimulacra2;
use App\Tests\Shared\StubPlatform;
use App\Tests\Unit\FsTestCase;
use org\bovigo\vfs\vfsStream;

use function str_repeat;

final class Ssimulacra2Test extends FsTestCase
{
    public function test_file_size_kb_ceil_rounding(): void
    {
        $platform = new StubPlatform();

        $file = vfsStream::newFile('image.png')->withContent(str_repeat('a', 1025))->at($this->root);

        $ssim = new Ssimulacra2($platform, 'ssimulacra2');

        self::assertSame(2, $ssim->fileSizeKb($file->url()));
    }
}
