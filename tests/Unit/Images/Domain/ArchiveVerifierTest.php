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
use function file_put_contents;
use function implode;
use function is_dir;
use function mkdir;
use function rmdir;
use function scandir;
use function str_repeat;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

final class ArchiveVerifierTest extends FsTestCase
{
    private StubPlatform $platform;
    private InMemoryProcessExecutor $processExecutor;
    private ArchiveVerifier $verifier;
    /** @var list<string> */
    private array $realTempDirs = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->processExecutor = new InMemoryProcessExecutor();
        $this->platform        = new StubPlatform();
        $this->platform->setTool('tar', '/usr/bin/tar');
        $this->verifier = new ArchiveVerifier($this->platform, $this->processExecutor);
    }

    protected function tearDown(): void
    {
        foreach ($this->realTempDirs as $realTempDir) {
            self::removeDirRecursively($realTempDir);
        }

        parent::tearDown();
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
        $realDir = $this->createRealTempDir();
        file_put_contents($realDir . '/photo1.arw', 'arw1');
        file_put_contents($realDir . '/photo2.arw', 'arw2');
        file_put_contents($realDir . '/raws-2.tar.xz', 'archive');

        $this->setupTarCommandToReturn(['photo1.arw', 'photo2.arw']);

        $arw1File = new SplFileInfo($realDir . '/photo1.arw');
        $arw2File = new SplFileInfo($realDir . '/photo2.arw');
        $files    = $this->createFileIterator([$arw1File, $arw2File]);

        $result = $this->verifier->getUnarchivedArwsByDir($files);

        self::assertEmpty($result);
    }

    public function test_get_unarchived_arws_by_dir_partially_archived(): void
    {
        $realDir = $this->createRealTempDir();
        file_put_contents($realDir . '/photo1.arw', 'arw1');
        file_put_contents($realDir . '/photo2.arw', 'arw2');
        file_put_contents($realDir . '/photo3.arw', 'arw3');
        file_put_contents($realDir . '/raws-2.tar.xz', 'archive');

        $this->setupTarCommandToReturn(['photo1.arw', 'photo3.arw']);

        $arw1File = new SplFileInfo($realDir . '/photo1.arw');
        $arw2File = new SplFileInfo($realDir . '/photo2.arw');
        $arw3File = new SplFileInfo($realDir . '/photo3.arw');
        $files    = $this->createFileIterator([$arw1File, $arw2File, $arw3File]);

        $result = $this->verifier->getUnarchivedArwsByDir($files);

        self::assertCount(1, $result);
        self::assertArrayHasKey($realDir, $result);
        self::assertCount(1, $result[$realDir]);
        self::assertContains($realDir . '/photo2.arw', $result[$realDir]);
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
        $realDir      = $this->createRealTempDir();
        $archivePath  = $realDir . '/raws-2.tar.xz';
        $archiveBytes = 100;
        file_put_contents($realDir . '/photo1.arw', 'arw1');
        file_put_contents($realDir . '/photo2.arw', 'arw2');
        file_put_contents($archivePath, str_repeat('a', $archiveBytes));

        $this->processExecutor = new InMemoryProcessExecutor(
            commandResults: ['-tf' => new ProcessResult(0, ['photo1.arw', 'photo2.arw'])],
        );
        $this->verifier        = new ArchiveVerifier($this->platform, $this->processExecutor);

        $arw1File = new SplFileInfo($realDir . '/photo1.arw');
        $arw2File = new SplFileInfo($realDir . '/photo2.arw');
        $files    = $this->createFileIterator([$arw1File, $arw2File]);

        $result = $this->verifier->verify($files);

        self::assertSame(2, $result->arwsFound);
        self::assertCount(2, $result->arwsToRemove);
        self::assertSame([], $result->unarchivedArws);
        self::assertSame([], $result->warnings);
        self::assertSame($archiveBytes, $result->archiveReplacementSize);
    }

    public function test_verify_partially_archived(): void
    {
        $realDir      = $this->createRealTempDir();
        $archivePath  = $realDir . '/raws-2.tar.xz';
        $archiveBytes = 100;
        file_put_contents($realDir . '/photo1.arw', 'arw1');
        file_put_contents($realDir . '/photo2.arw', 'arw2');
        file_put_contents($realDir . '/photo3.arw', 'arw3');
        file_put_contents($archivePath, str_repeat('a', $archiveBytes));

        $this->processExecutor = new InMemoryProcessExecutor(
            commandResults: ['-tf' => new ProcessResult(0, ['photo1.arw', 'photo3.arw'])],
        );
        $this->verifier        = new ArchiveVerifier($this->platform, $this->processExecutor);

        $arw1File = new SplFileInfo($realDir . '/photo1.arw');
        $arw2File = new SplFileInfo($realDir . '/photo2.arw');
        $arw3File = new SplFileInfo($realDir . '/photo3.arw');
        $files    = $this->createFileIterator([$arw1File, $arw2File, $arw3File]);

        $result = $this->verifier->verify($files);

        self::assertSame(3, $result->arwsFound);
        self::assertCount(2, $result->arwsToRemove);
        self::assertArrayHasKey($realDir, $result->unarchivedArws);
        self::assertCount(1, $result->unarchivedArws[$realDir]);
        self::assertContains($realDir . '/photo2.arw', $result->unarchivedArws[$realDir]);
        self::assertCount(1, $result->warnings);
        self::assertStringContainsString('1 ARWs in', $result->warnings[0]);
        self::assertSame(2, count($result->arwsToRemove));
        self::assertSame($archiveBytes, $result->archiveReplacementSize);
    }

    public function test_verify_multiple_directories(): void
    {
        $dir1          = $this->createRealTempDir();
        $dir2          = $this->createRealTempDir();
        $archive1Path  = $dir1 . '/raws-1.tar.xz';
        $archive2Path  = $dir2 . '/raws-1.tar.xz';
        $archive1Bytes = 90;
        $archive2Bytes = 110;
        file_put_contents($dir1 . '/photo1.arw', 'arw1');
        file_put_contents($dir2 . '/photo2.arw', 'arw2');
        file_put_contents($archive1Path, str_repeat('a', $archive1Bytes));
        file_put_contents($archive2Path, str_repeat('b', $archive2Bytes));

        $this->processExecutor = new InMemoryProcessExecutor(
            commandResults: [
                $archive1Path => new ProcessResult(0, ['photo1.arw']),
                $archive2Path => new ProcessResult(0, ['photo2.arw']),
            ],
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
        self::assertSame($archive1Bytes + $archive2Bytes, $result->archiveReplacementSize);
    }

    /** @param array<string> $filenames */
    private function setupTarCommandToReturn(array $filenames): void
    {
        $output = explode("\n", implode("\n", $filenames));

        $this->processExecutor = new InMemoryProcessExecutor(
            commandResults: ['-tf' => new ProcessResult(0, $output)],
        );
        $this->verifier        = new ArchiveVerifier($this->platform, $this->processExecutor);
    }

    private function createRealTempDir(): string
    {
        $path = sys_get_temp_dir() . '/gallinor-archive-verifier-' . uniqid('', true);
        if (! mkdir($path, 0o755, true) && ! is_dir($path)) {
            self::fail('Failed to create temporary directory for ArchiveVerifierTest.');
        }

        $this->realTempDirs[] = $path;

        return $path;
    }

    private static function removeDirRecursively(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $entries = scandir($path);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $candidate = $path . '/' . $entry;
            if (is_dir($candidate)) {
                self::removeDirRecursively($candidate);
                continue;
            }

            @unlink($candidate);
        }

        @rmdir($path);
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
