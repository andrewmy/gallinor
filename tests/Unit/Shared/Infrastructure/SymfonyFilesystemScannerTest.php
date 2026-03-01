<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure;

use App\Shared\Infrastructure\SymfonyFilesystemScanner;
use Generator;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

use function file_put_contents;
use function is_dir;
use function mkdir;
use function rmdir;
use function scandir;
use function sort;
use function sprintf;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

final class SymfonyFilesystemScannerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/gallinor-scanner-test-' . uniqid('', true);
        mkdir($this->tmpDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        self::removeDir($this->tmpDir);

        parent::tearDown();
    }

    public function test_scan_directories_yields_files_recursively_for_directory_path(): void
    {
        $nestedDir = $this->tmpDir . '/nested';
        mkdir($nestedDir, 0o755, true);

        $topLevelFile = $this->tmpDir . '/a.jpg';
        $nestedFile   = $nestedDir . '/b.arw';

        file_put_contents($topLevelFile, 'a');
        file_put_contents($nestedFile, 'b');

        $scanner = new SymfonyFilesystemScanner(new Filesystem());
        $paths   = self::collectPaths($scanner->scanDirectories([$this->tmpDir]));

        sort($paths);

        self::assertSame([$topLevelFile, $nestedFile], $paths);
    }

    public function test_scan_directories_yields_single_file_when_path_points_to_file(): void
    {
        $filePath = $this->tmpDir . '/single.mp4';
        file_put_contents($filePath, 'video');

        $scanner = new SymfonyFilesystemScanner(new Filesystem());
        $paths   = self::collectPaths($scanner->scanDirectories([$filePath]));

        self::assertSame([$filePath], $paths);
    }

    public function test_scan_directories_throws_when_path_does_not_exist(): void
    {
        $scanner     = new SymfonyFilesystemScanner(new Filesystem());
        $missingPath = $this->tmpDir . '/missing';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(sprintf('Path not found: %s', $missingPath));

        self::collectPaths($scanner->scanDirectories([$missingPath]));
    }

    /** @return list<string> */
    private static function collectPaths(Generator $files): array
    {
        $paths = [];
        foreach ($files as $file) {
            $paths[] = $file->getPathname();
        }

        return $paths;
    }

    private static function removeDir(string $path): void
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
                self::removeDir($candidate);
                continue;
            }

            unlink($candidate);
        }

        rmdir($path);
    }
}
