<?php

declare(strict_types=1);

namespace App\Tests\Unit\Images\Domain;

use App\Images\Domain\RawArchiver;
use App\Shared\Domain\ProcessResult;
use App\Tests\Shared\InMemoryProcessExecutor;
use App\Tests\Shared\StubPlatform;
use App\Tests\Unit\FsTestCase;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use org\bovigo\vfs\vfsStream;
use RuntimeException;

use function count;
use function glob;
use function sys_get_temp_dir;

use const DIRECTORY_SEPARATOR;

final class RawArchiverTest extends FsTestCase
{
    private InMemoryProcessExecutor $processExecutor;
    private StubPlatform $platform;
    private TestHandler $logHandler;
    private Logger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->processExecutor = new InMemoryProcessExecutor();
        $this->platform        = new StubPlatform();
        $this->platform->setTool('xz', '/usr/bin/xz');
        $this->logHandler = new TestHandler();
        $this->logger     = new Logger('test', [$this->logHandler]);
    }

    public function test_archive_windows_creates_tar_then_compresses_with_xz(): void
    {
        $root = vfsStream::setup('root');
        vfsStream::newFile('photo.arw')->withContent('arw data')->at($root);
        vfsStream::newFile('raws-1.tar.xz')->withContent('archive data')->at($root);
        $directory = vfsStream::url('root');
        $arwFile   = $directory . DIRECTORY_SEPARATOR . 'photo.arw';

        $this->platform->isWindows = true;
        $archiver                  = new RawArchiver($this->platform, $this->logger, $this->processExecutor);

        $result = $archiver->archive($directory, [$arwFile]);

        self::assertIsInt($result);
        self::assertCount(1, $this->logHandler->getRecords());
        self::assertSame('Archived ARWs', $this->logHandler->getRecords()[0]['message']);
        self::assertArrayHasKey('directory', $this->logHandler->getRecords()[0]['context']);
        self::assertArrayHasKey('file_count', $this->logHandler->getRecords()[0]['context']);
        self::assertArrayHasKey('archive_path', $this->logHandler->getRecords()[0]['context']);
    }

    public function test_archive_unix_creates_archive_via_piped_command(): void
    {
        $root = vfsStream::setup('root');
        vfsStream::newFile('photo.arw')->withContent('arw data')->at($root);
        vfsStream::newFile('raws-1.tar.xz')->withContent('archive data')->at($root);
        $directory = vfsStream::url('root');
        $arwFile   = $directory . DIRECTORY_SEPARATOR . 'photo.arw';

        $this->platform->isWindows = false;
        $archiver                  = new RawArchiver($this->platform, $this->logger, $this->processExecutor);

        $result = $archiver->archive($directory, [$arwFile]);

        self::assertIsInt($result);
        self::assertCount(1, $this->logHandler->getRecords());
        self::assertSame('Archived ARWs', $this->logHandler->getRecords()[0]['message']);
        self::assertArrayHasKey('directory', $this->logHandler->getRecords()[0]['context']);
        self::assertArrayHasKey('file_count', $this->logHandler->getRecords()[0]['context']);
        self::assertArrayHasKey('archive_path', $this->logHandler->getRecords()[0]['context']);
    }

    public function test_archive_naming_format_includes_file_count(): void
    {
        $root = vfsStream::setup('root');
        vfsStream::newFile('photo1.arw')->at($root);
        vfsStream::newFile('photo2.arw')->at($root);
        vfsStream::newFile('photo3.arw')->at($root);
        vfsStream::newFile('raws-3.tar.xz')->withContent('archive')->at($root);
        $directory = vfsStream::url('root');
        $arwFile1  = $directory . DIRECTORY_SEPARATOR . 'photo1.arw';
        $arwFile2  = $directory . DIRECTORY_SEPARATOR . 'photo2.arw';
        $arwFile3  = $directory . DIRECTORY_SEPARATOR . 'photo3.arw';

        $this->platform->isWindows = false;
        $archiver                  = new RawArchiver($this->platform, $this->logger, $this->processExecutor);

        $archiver->archive($directory, [$arwFile1, $arwFile2, $arwFile3]);

        self::assertCount(1, $this->logHandler->getRecords());
        $context = $this->logHandler->getRecords()[0]['context'];
        self::assertArrayHasKey('archive_path', $context);
        self::assertStringContainsString('raws-3.tar.xz', $context['archive_path']);
    }

    public function test_log_includes_directory_and_file_count(): void
    {
        $root = vfsStream::setup('root');
        vfsStream::newFile('photo1.arw')->at($root);
        vfsStream::newFile('photo2.arw')->at($root);
        vfsStream::newFile('raws-2.tar.xz')->withContent('archive')->at($root);
        $directory = vfsStream::url('root');
        $arwFile1  = $directory . DIRECTORY_SEPARATOR . 'photo1.arw';
        $arwFile2  = $directory . DIRECTORY_SEPARATOR . 'photo2.arw';

        $this->platform->isWindows = false;
        $archiver                  = new RawArchiver($this->platform, $this->logger, $this->processExecutor);

        $archiver->archive($directory, [$arwFile1, $arwFile2]);

        self::assertCount(1, $this->logHandler->getRecords());
        $context = $this->logHandler->getRecords()[0]['context'];
        self::assertSame($directory, $context['directory']);
        self::assertSame(2, $context['file_count']);
        self::assertStringEndsWith('raws-2.tar.xz', $context['archive_path']);
    }

    public function test_list_file_cleaned_up_on_success(): void
    {
        $root = vfsStream::setup('root');
        vfsStream::newFile('photo.arw')->at($root);
        vfsStream::newFile('raws-1.tar.xz')->withContent('archive')->at($root);
        $directory = vfsStream::url('root');
        $arwFile   = $directory . DIRECTORY_SEPARATOR . 'photo.arw';

        $this->platform->isWindows = false;
        $archiver                  = new RawArchiver($this->platform, $this->logger, $this->processExecutor);

        $listFilesBefore = glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . '*-arwlist.txt');
        $archiver->archive($directory, [$arwFile]);
        $listFilesAfter = glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . '*-arwlist.txt');

        self::assertSame(count($listFilesBefore), count($listFilesAfter));
    }

    public function test_windows_tar_failure_throws_exception(): void
    {
        vfsStream::newFile('photo.arw')->at(vfsStream::setup('root'));
        $directory = vfsStream::url('root');
        $arwFile   = $directory . DIRECTORY_SEPARATOR . 'photo.arw';

        $this->platform->isWindows = true;
        $this->processExecutor     = new InMemoryProcessExecutor(
            commandResults: ['tar' => new ProcessResult(1, ['tar error'])],
        );
        $archiver                  = new RawArchiver($this->platform, $this->logger, $this->processExecutor);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tar failed');

        $archiver->archive($directory, [$arwFile]);
        self::assertEmpty($this->logHandler->getRecords());
    }

    public function test_windows_xz_failure_throws_exception(): void
    {
        vfsStream::newFile('photo.arw')->at(vfsStream::setup('root'));
        $directory = vfsStream::url('root');
        $arwFile   = $directory . DIRECTORY_SEPARATOR . 'photo.arw';

        $this->platform->isWindows = true;
        $this->processExecutor     = new InMemoryProcessExecutor(
            commandResults: [
                'tar' => new ProcessResult(0, []),
                '/usr/bin/xz' => new ProcessResult(1, ['xz error']),
            ],
        );
        $archiver                  = new RawArchiver($this->platform, $this->logger, $this->processExecutor);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('xz failed');

        $archiver->archive($directory, [$arwFile]);
    }

    public function test_unix_piped_failure_throws_exception(): void
    {
        vfsStream::newFile('photo.arw')->at(vfsStream::setup('root'));
        $directory = vfsStream::url('root');
        $arwFile   = $directory . DIRECTORY_SEPARATOR . 'photo.arw';

        $this->platform->isWindows = false;
        $this->processExecutor     = new InMemoryProcessExecutor(
            commandResults: ['|' => new ProcessResult(1, ['piped error'])],
        );
        $archiver                  = new RawArchiver($this->platform, $this->logger, $this->processExecutor);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Archive creation failed');

        $archiver->archive($directory, [$arwFile]);
        self::assertEmpty($this->logHandler->getRecords());
    }

    public function test_list_file_cleaned_up_after_success(): void
    {
        vfsStream::newFile('photo.arw')->at(vfsStream::setup('root'));
        vfsStream::newFile('raws-1.tar.xz')->withContent('archive')->at(vfsStream::setup('root'));
        $directory = vfsStream::url('root');
        $arwFile   = $directory . DIRECTORY_SEPARATOR . 'photo.arw';

        $this->platform->isWindows = false;
        $archiver                  = new RawArchiver($this->platform, $this->logger, $this->processExecutor);

        $listFilesBefore = glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . '*-arwlist.txt');
        $archiver->archive($directory, [$arwFile]);
        $listFilesAfter = glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . '*-arwlist.txt');

        self::assertSame(count($listFilesBefore), count($listFilesAfter));
    }
}
