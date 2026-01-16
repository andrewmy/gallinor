<?php

declare(strict_types=1);

namespace App\Tests\Unit\Images\Domain;

use App\Images\Domain\ArchiveVerifier;
use App\Shared\Domain\ProcessResult;
use App\Tests\Shared\InMemoryProcessExecutor;
use App\Tests\Shared\StubPlatform;
use App\Tests\Unit\FsTestCase;
use Generator;
use org\bovigo\vfs\vfsStream;
use SplFileInfo;

use function count;
use function explode;
use function implode;

final class ArchiveVerifierTest extends FsTestCase
{
    private StubPlatform $platform;
    private InMemoryProcessExecutor $processExecutor;
    private ArchiveVerifier $verifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->processExecutor = new InMemoryProcessExecutor();
        $this->platform        = new StubPlatform();
        $this->platform->setTool('tar', '/usr/bin/tar');
        $this->verifier = new ArchiveVerifier($this->platform, $this->processExecutor);
    }

    public function test_get_unarchived_arws_by_dir_empty_input(): void
    {
        $files = $this->createFileIterator([]);

        $result = $this->verifier->getUnarchivedArwsByDir($files);

        self::assertSame([], $result);
    }

    public function test_get_unarchived_arws_by_dir_filters_non_arw_files(): void
    {
        $root = vfsStream::setup('root');
        vfsStream::newFile('photo.jpg')->at($root);
        vfsStream::newFile('document.txt')->at($root);
        vfsStream::newFile('photo.jpeg')->at($root);

        $jpgFile  = new SplFileInfo(vfsStream::url('root/photo.jpg'));
        $txtFile  = new SplFileInfo(vfsStream::url('root/document.txt'));
        $jpegFile = new SplFileInfo(vfsStream::url('root/photo.jpeg'));

        $files = $this->createFileIterator([$jpgFile, $txtFile, $jpegFile]);

        $result = $this->verifier->getUnarchivedArwsByDir($files);

        self::assertSame([], $result);
    }

    public function test_get_unarchived_arws_by_dir_all_archived(): void
    {
        self::markTestIncomplete('Requires proper vfsStream glob() support');

        $root = vfsStream::setup('root');
        vfsStream::newFile('photo1.arw')->at($root);
        vfsStream::newFile('photo2.arw')->at($root);
        vfsStream::newFile('raws-2.tar.xz')->withContent('archive')->at($root);

        $this->setupTarCommandToReturn(['photo1.arw', 'photo2.arw']);

        $arw1File = new SplFileInfo(vfsStream::url('root/photo1.arw'));
        $arw2File = new SplFileInfo(vfsStream::url('root/photo2.arw'));
        $files    = $this->createFileIterator([$arw1File, $arw2File]);

        $result = $this->verifier->getUnarchivedArwsByDir($files);

        self::assertEmpty($result);
    }

    public function test_get_unarchived_arws_by_dir_partially_archived(): void
    {
        self::markTestIncomplete('Requires proper vfsStream glob() support');

        $root = vfsStream::setup('root');
        vfsStream::newFile('photo1.arw')->at($root);
        vfsStream::newFile('photo2.arw')->at($root);
        vfsStream::newFile('photo3.arw')->at($root);
        vfsStream::newFile('raws-2.tar.xz')->withContent('archive')->at($root);

        $this->setupTarCommandToReturn(['photo1.arw', 'photo3.arw']);

        $arw1File = new SplFileInfo(vfsStream::url('root/photo1.arw'));
        $arw2File = new SplFileInfo(vfsStream::url('root/photo2.arw'));
        $arw3File = new SplFileInfo(vfsStream::url('root/photo3.arw'));
        $files    = $this->createFileIterator([$arw1File, $arw2File, $arw3File]);

        $result = $this->verifier->getUnarchivedArwsByDir($files);

        self::assertCount(1, $result);
        self::assertArrayHasKey(vfsStream::url('root'), $result);
        self::assertCount(1, $result[vfsStream::url('root')]);
        self::assertContains(vfsStream::url('root/photo2.arw'), $result[vfsStream::url('root')]);
    }

    public function test_get_unarchived_arws_by_dir_no_archives(): void
    {
        $root = vfsStream::setup('root');
        vfsStream::newFile('photo1.arw')->at($root);
        vfsStream::newFile('photo2.arw')->at($root);

        $this->setupTarCommandToReturn([]);

        $arw1File = new SplFileInfo(vfsStream::url('root/photo1.arw'));
        $arw2File = new SplFileInfo(vfsStream::url('root/photo2.arw'));
        $files    = $this->createFileIterator([$arw1File, $arw2File]);

        $result = $this->verifier->getUnarchivedArwsByDir($files);

        self::assertCount(1, $result);
        self::assertArrayHasKey(vfsStream::url('root'), $result);
        self::assertCount(2, $result[vfsStream::url('root')]);
    }

    public function test_verify_empty_iterator(): void
    {
        $files = $this->createFileIterator([]);

        $result = $this->verifier->verify($files);

        self::assertSame(0, $result->arwsFound);
        self::assertSame([], $result->arwsToRemove);
        self::assertSame([], $result->unarchivedArws);
        self::assertSame([], $result->warnings);
        self::assertSame(0, $result->arwsSkipped);
        self::assertSame(0, $result->archiveReplacementSize);
    }

    public function test_verify_with_progress_callback(): void
    {
        $root = vfsStream::setup('root');
        vfsStream::newFile('photo1.arw')->at($root);
        vfsStream::newFile('photo2.arw')->at($root);
        vfsStream::newFile('raws-2.tar.xz')->withContent('archive')->at($root);

        $this->setupTarCommandToReturn(['photo1.arw', 'photo2.arw']);

        $arw1File = new SplFileInfo(vfsStream::url('root/photo1.arw'));
        $arw2File = new SplFileInfo(vfsStream::url('root/photo2.arw'));

        $progressCalls = [];
        $files         = $this->createFileIterator([$arw1File, $arw2File], static function ($path) use (&$progressCalls): void {
            $progressCalls[] = $path;
        });

        $result = $this->verifier->verify($files, static function ($path) use (&$progressCalls): void {
            $progressCalls[] = $path;
        });

        self::assertSame(2, count($progressCalls));
        self::assertSame(vfsStream::url('root/photo1.arw'), $progressCalls[0]);
        self::assertSame(vfsStream::url('root/photo2.arw'), $progressCalls[1]);
    }

    public function test_verify_all_archived(): void
    {
        self::markTestIncomplete('Requires proper vfsStream glob() support');

        $root = vfsStream::setup('root');
        vfsStream::newFile('photo1.arw')->at($root);
        vfsStream::newFile('photo2.arw')->at($root);
        vfsStream::newFile('raws-2.tar.xz')->withContent('archive')->at($root);

        $this->processExecutor = new InMemoryProcessExecutor(
            commandResults: [ProcessResult::class => new ProcessResult(0, ['photo1.arw', 'photo2.arw'])],
            fileSizes: [$root->url() => 100],
        );
        $this->verifier        = new ArchiveVerifier($this->platform, $this->processExecutor);

        $arw1File = new SplFileInfo(vfsStream::url('root/photo1.arw'));
        $arw2File = new SplFileInfo(vfsStream::url('root/photo2.arw'));
        $files    = $this->createFileIterator([$arw1File, $arw2File]);

        $result = $this->verifier->verify($files);

        self::assertSame(2, $result->arwsFound);
        self::assertCount(2, $result->arwsToRemove);
        self::assertSame([], $result->unarchivedArws);
        self::assertSame([], $result->warnings);
        self::assertGreaterThan(0, $result->archiveReplacementSize);
    }

    public function test_verify_partially_archived(): void
    {
        self::markTestIncomplete('Requires proper vfsStream glob() support');

        $root = vfsStream::setup('root');
        vfsStream::newFile('photo1.arw')->at($root);
        vfsStream::newFile('photo2.arw')->at($root);
        vfsStream::newFile('photo3.arw')->at($root);
        vfsStream::newFile('raws-2.tar.xz')->withContent('archive')->at($root);

        $this->processExecutor = new InMemoryProcessExecutor(
            commandResults: [ProcessResult::class => new ProcessResult(0, ['photo1.arw', 'photo3.arw'])],
            fileSizes: [$root->url() => 100],
        );
        $this->verifier        = new ArchiveVerifier($this->platform, $this->processExecutor);

        $arw1File = new SplFileInfo(vfsStream::url('root/photo1.arw'));
        $arw2File = new SplFileInfo(vfsStream::url('root/photo2.arw'));
        $arw3File = new SplFileInfo(vfsStream::url('root/photo3.arw'));
        $files    = $this->createFileIterator([$arw1File, $arw2File, $arw3File]);

        $result = $this->verifier->verify($files);

        self::assertSame(3, $result->arwsFound);
        self::assertCount(2, $result->arwsToRemove);
        self::assertArrayHasKey(vfsStream::url('root'), $result->unarchivedArws);
        self::assertCount(1, $result->unarchivedArws[vfsStream::url('root')]);
        self::assertContains(vfsStream::url('root/photo2.arw'), $result->unarchivedArws[vfsStream::url('root')]);
        self::assertCount(1, $result->warnings);
        self::assertStringContainsString('1 ARWs in', $result->warnings[0]);
        self::assertSame(2, count($result->arwsToRemove));
    }

    public function test_verify_multiple_directories(): void
    {
        self::markTestIncomplete('Requires proper vfsStream glob() support');

        $root1 = vfsStream::setup('root1');
        $root2 = vfsStream::setup('root2');

        vfsStream::newFile('photo1.arw')->at($root1);
        vfsStream::newFile('photo2.arw')->at($root2);

        vfsStream::newFile('raws-1.tar.xz')->withContent('archive1')->at($root1);
        vfsStream::newFile('raws-1.tar.xz')->withContent('archive2')->at($root2);

        $dir1 = vfsStream::url('root1');
        $dir2 = vfsStream::url('root2');

        $this->processExecutor = new InMemoryProcessExecutor(
            commandResults: ['tar' => new ProcessResult(0, ['photo1.arw'])],
            fileSizes: [$dir1 => 100, $dir2 => 100],
        );
        $this->verifier        = new ArchiveVerifier($this->platform, $this->processExecutor);

        $arw1File = new SplFileInfo($dir1 . '/photo1.arw');
        $arw2File = new SplFileInfo($dir2 . '/photo2.arw');
        $files    = $this->createFileIterator([$arw1File, $arw2File]);

        $result = $this->verifier->verify($files);

        self::assertSame(2, $result->arwsFound);
        self::assertCount(2, $result->arwsToRemove);
        self::assertSame([], $result->unarchivedArws);
        self::assertSame([], $result->warnings);
    }

    /** @param array<string> $filenames */
    private function setupTarCommandToReturn(array $filenames): void
    {
        $output = explode("\n", implode("\n", $filenames));

        $this->processExecutor = new InMemoryProcessExecutor(
            commandResults: [ProcessResult::class => new ProcessResult(0, $output)],
            fileSizes: [vfsStream::url('root') => 100],
        );
        $this->verifier        = new ArchiveVerifier($this->platform, $this->processExecutor);
    }

    /** @param array<SplFileInfo> $files */
    private function createFileIterator(array $files, callable|null $callback = null): Generator
    {
        foreach ($files as $file) {
            if ($callback !== null) {
                $callback($file->getPathname());
            }

            yield $file;
        }
    }
}
