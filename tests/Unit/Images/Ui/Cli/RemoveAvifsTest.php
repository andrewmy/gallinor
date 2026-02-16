<?php

declare(strict_types=1);

namespace App\Tests\Unit\Images\Ui\Cli;

use App\Images\Ui\Cli\RemoveAvifs;
use App\Shared\Ui\Cli\CliHelper;
use App\Shared\Ui\Cli\Timing;
use App\Tests\Shared\FixedFilesystemScanner;
use App\Tests\Unit\FsTestCase;
use org\bovigo\vfs\vfsStream;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\BufferedOutput;

use function file_exists;

final class RemoveAvifsTest extends FsTestCase
{
    public function test_remove_avifs_removes_existing_candidates(): void
    {
        vfsStream::newFile('a.avif')->withContent('123456')->at($this->root);
        vfsStream::newFile('a.heic')->withContent('12')->at($this->root);

        $command = new RemoveAvifs(
            new CliHelper(),
            new FixedFilesystemScanner([$this->vfsUrl('a.avif')]),
            new Timing(init: 0.1),
        );
        $output  = new BufferedOutput();

        $exitCode = $command->__invoke($output, false, [$this->vfsUrl('')]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertFalse(file_exists($this->vfsUrl('a.avif')));

        $display = $output->fetch();
        self::assertStringContainsString('Removed: 1', $display);
        self::assertStringContainsString('Skipped missing: 0', $display);
        self::assertStringContainsString('Errored: 0', $display);
    }

    public function test_remove_avifs_skips_missing_candidates_without_erroring(): void
    {
        vfsStream::newFile('a.heic')->withContent('12')->at($this->root);

        $command = new RemoveAvifs(
            new CliHelper(),
            new FixedFilesystemScanner([$this->vfsUrl('a.avif')]),
            new Timing(init: 0.1),
        );
        $output  = new BufferedOutput();

        $exitCode = $command->__invoke($output, false, [$this->vfsUrl('')]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $display = $output->fetch();
        self::assertStringContainsString('Skipped missing AVIF: a.avif', $display);
        self::assertStringContainsString('Removed: 0', $display);
        self::assertStringContainsString('Skipped missing: 1', $display);
        self::assertStringContainsString('Errored: 0', $display);
    }
}
