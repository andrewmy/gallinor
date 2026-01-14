<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;
use PHPUnit\Framework\TestCase;

use function ltrim;
use function sprintf;

abstract class FsTestCase extends TestCase
{
    protected vfsStreamDirectory $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = vfsStream::setup('root');
    }

    protected function vfsUrl(string $path): string
    {
        return sprintf('vfs://root/%s', ltrim($path, '/'));
    }
}
