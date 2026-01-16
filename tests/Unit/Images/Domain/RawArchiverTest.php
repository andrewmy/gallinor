<?php

declare(strict_types=1);

namespace App\Tests\Unit\Images\Domain;

use App\Images\Domain\RawArchiver;
use App\Shared\Domain\Platform;
use App\Shared\Domain\ProcessResult;
use App\Tests\Shared\InMemoryProcessExecutor;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

use function count;
use function glob;
use function str_contains;
use function str_ends_with;
use function sys_get_temp_dir;

use const DIRECTORY_SEPARATOR;

final class RawArchiverTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private MockInterface|Platform $platform;
    private InMemoryProcessExecutor $processExecutor;
    private RawArchiver $archiver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->processExecutor = new InMemoryProcessExecutor();
        $this->platform        = Mockery::mock(Platform::class);
        $this->platform->allows()->findTool('xz')->andReturn('/usr/bin/xz');
    }

    public function test_archive_windows_creates_tar_then_compresses_with_xz(): void
    {
        $root = vfsStream::setup('root');
        vfsStream::newFile('photo.arw')->withContent('arw data')->at($root);
        vfsStream::newFile('raws-1.tar.xz')->withContent('archive data')->at($root);
        $directory = vfsStream::url('root');
        $arwFile   = $directory . DIRECTORY_SEPARATOR . 'photo.arw';

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->expects()->info('Archived ARWs', Mockery::on(static fn ($context) => isset($context['directory']) && isset($context['file_count']) && isset($context['archive_path'])));

        $this->platform->allows()->isWindows()->andReturn(true);
        $this->archiver = new RawArchiver($this->platform, $logger, $this->processExecutor);

        $result = $this->archiver->archive($directory, [$arwFile]);

        self::assertIsInt($result);
    }

    public function test_archive_unix_creates_archive_via_piped_command(): void
    {
        $root = vfsStream::setup('root');
        vfsStream::newFile('photo.arw')->withContent('arw data')->at($root);
        vfsStream::newFile('raws-1.tar.xz')->withContent('archive data')->at($root);
        $directory = vfsStream::url('root');
        $arwFile   = $directory . DIRECTORY_SEPARATOR . 'photo.arw';

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->expects()->info('Archived ARWs', Mockery::on(static fn ($context) => isset($context['directory']) && isset($context['file_count']) && isset($context['archive_path'])));

        $this->platform->allows()->isWindows()->andReturn(false);
        $this->archiver = new RawArchiver($this->platform, $logger, $this->processExecutor);

        $result = $this->archiver->archive($directory, [$arwFile]);

        self::assertIsInt($result);
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

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->expects()->info('Archived ARWs', Mockery::on(static fn ($context) => isset($context['archive_path']) && str_contains($context['archive_path'], 'raws-3.tar.xz')));

        $this->platform->allows()->isWindows()->andReturn(false);
        $this->archiver = new RawArchiver($this->platform, $logger, $this->processExecutor);

        $this->archiver->archive($directory, [$arwFile1, $arwFile2, $arwFile3]);
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

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->expects()->info('Archived ARWs', Mockery::on(static fn ($context) => $context['directory'] === $directory &&
            $context['file_count'] === 2 &&
            str_ends_with($context['archive_path'], 'raws-2.tar.xz')));

        $this->platform->allows()->isWindows()->andReturn(false);
        $this->archiver = new RawArchiver($this->platform, $logger, $this->processExecutor);

        $this->archiver->archive($directory, [$arwFile1, $arwFile2]);
    }

    public function test_list_file_cleaned_up_on_success(): void
    {
        $root = vfsStream::setup('root');
        vfsStream::newFile('photo.arw')->at($root);
        vfsStream::newFile('raws-1.tar.xz')->withContent('archive')->at($root);
        $directory = vfsStream::url('root');
        $arwFile   = $directory . DIRECTORY_SEPARATOR . 'photo.arw';

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->allows()->info(Mockery::any(), Mockery::any());

        $this->platform->allows()->isWindows()->andReturn(false);
        $this->archiver = new RawArchiver($this->platform, $logger, $this->processExecutor);

        $listFilesBefore = glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . '*-arwlist.txt');
        $this->archiver->archive($directory, [$arwFile]);
        $listFilesAfter = glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . '*-arwlist.txt');

        self::assertSame(count($listFilesBefore), count($listFilesAfter));
    }

    public function test_windows_tar_failure_throws_exception(): void
    {
        vfsStream::newFile('photo.arw')->at(vfsStream::setup('root'));
        $directory = vfsStream::url('root');
        $arwFile   = $directory . DIRECTORY_SEPARATOR . 'photo.arw';

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->allows()->info(Mockery::any(), Mockery::any())->never();

        $this->platform->allows()->isWindows()->andReturn(true);
        $this->processExecutor = new InMemoryProcessExecutor(
            commandResults: ['tar' => new ProcessResult(1, ['tar error'])],
        );
        $this->archiver        = new RawArchiver($this->platform, $logger, $this->processExecutor);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tar failed');

        $this->archiver->archive($directory, [$arwFile]);
    }

    public function test_windows_xz_failure_throws_exception(): void
    {
        self::markTestIncomplete('InMemoryProcessExecutor needs enhancement to properly simulate tar file creation');

        vfsStream::newFile('photo.arw')->at(vfsStream::setup('root'));
        $directory = vfsStream::url('root');
        $arwFile   = $directory . DIRECTORY_SEPARATOR . 'photo.arw';

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->allows()->info(Mockery::any(), Mockery::any());

        $this->platform->allows()->isWindows()->andReturn(true);
        $this->processExecutor = new InMemoryProcessExecutor(
            commandResults: [
                'tar' => new ProcessResult(0, []),
                'xz' => new ProcessResult(1, ['xz error']),
            ],
        );
        $archiver              = new RawArchiver($this->platform, $logger, $this->processExecutor);

        $this->expectException(RuntimeException::class);

        $archiver->archive($directory, [$arwFile]);
    }

    public function test_unix_piped_failure_throws_exception(): void
    {
        vfsStream::newFile('photo.arw')->at(vfsStream::setup('root'));
        $directory = vfsStream::url('root');
        $arwFile   = $directory . DIRECTORY_SEPARATOR . 'photo.arw';

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->allows()->info(Mockery::any(), Mockery::any());

        $this->platform->allows()->isWindows()->andReturn(false);
        $this->processExecutor = new InMemoryProcessExecutor(
            commandResults: ['|' => new ProcessResult(1, ['piped error'])],
        );
        $archiver              = new RawArchiver($this->platform, $logger, $this->processExecutor);

        $this->expectException(RuntimeException::class);

        $archiver->archive($directory, [$arwFile]);
    }

    public function test_list_file_cleaned_up_after_success(): void
    {
        vfsStream::newFile('photo.arw')->at(vfsStream::setup('root'));
        vfsStream::newFile('raws-1.tar.xz')->withContent('archive')->at(vfsStream::setup('root'));
        $directory = vfsStream::url('root');
        $arwFile   = $directory . DIRECTORY_SEPARATOR . 'photo.arw';

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->allows()->info(Mockery::any(), Mockery::any());

        $this->platform->allows()->isWindows()->andReturn(false);
        $archiver = new RawArchiver($this->platform, $logger, $this->processExecutor);

        $listFilesBefore = glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . '*-arwlist.txt');
        $archiver->archive($directory, [$arwFile]);
        $listFilesAfter = glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . '*-arwlist.txt');

        self::assertSame(count($listFilesBefore), count($listFilesAfter));
    }
}
