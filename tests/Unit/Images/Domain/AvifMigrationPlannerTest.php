<?php

declare(strict_types=1);

namespace App\Tests\Unit\Images\Domain;

use App\Images\Domain\AvifMigrationPlanner;
use App\Shared\Domain\FilesystemScanner;
use App\Tests\Unit\FsTestCase;
use Generator;
use org\bovigo\vfs\vfsStream;
use SplFileInfo;

final class AvifMigrationPlannerTest extends FsTestCase
{
    public function test_plan_splits_avifs_by_existing_heic(): void
    {
        vfsStream::newFile('a.avif')->at($this->root);
        vfsStream::newFile('a.heic')->at($this->root);
        vfsStream::newFile('b.avif')->at($this->root);
        vfsStream::newFile('c.jpg')->at($this->root);

        $scanner = new class ([$this->vfsUrl('a.avif'), $this->vfsUrl('b.avif'), $this->vfsUrl('c.jpg')]) implements FilesystemScanner {
            /** @param list<string> $paths */
            public function __construct(private array $paths)
            {
            }

            /** @param list<string> $directories */
            public function scanDirectories(array $directories): Generator
            {
                foreach ($this->paths as $path) {
                    yield new SplFileInfo($path);
                }
            }
        };

        $plan = (new AvifMigrationPlanner($scanner))->plan([$this->vfsUrl('')]);

        self::assertSame([$this->vfsUrl('a.avif'), $this->vfsUrl('b.avif')], $plan->allAvifs);
        self::assertSame([$this->vfsUrl('a.avif')], $plan->alreadyMigratedAvifs);
        self::assertSame([$this->vfsUrl('b.avif')], $plan->toMigrateAvifs);
    }
}
