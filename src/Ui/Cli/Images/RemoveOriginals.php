<?php

declare(strict_types=1);

namespace App\Ui\Cli\Images;

use App\Domain\Platform;
use App\Ui\Cli\CliHelper;
use FilesystemIterator;
use Psr\Log\LoggerInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

use function assert;
use function basename;
use function count;
use function dirname;
use function escapeshellarg;
use function exec;
use function explode;
use function file_exists;
use function filesize;
use function glob;
use function in_array;
use function microtime;
use function number_format;
use function pathinfo;
use function preg_match;
use function rtrim;
use function shell_exec;
use function sprintf;
use function strtolower;
use function trim;
use function unlink;

use const DIRECTORY_SEPARATOR;
use const PATHINFO_FILENAME;
use const PHP_EOL;

#[AsCommand(name: 'images:remove-originals', description: 'Remove original JPEGs and ARWs after conversion/archiving')]
final class RemoveOriginals extends Command
{
    private Platform $platform;

    /** @var array<string, string> */
    private array $toolPaths = [];

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly CliHelper $cliHelper,
    ) {
        parent::__construct();
    }

    /** @param list<string> $directories */
    public function __invoke(
        OutputInterface $output,
        #[Option]
        bool $dryRun = false,
        #[Argument]
        array $directories = [],
    ): int {
        $output->writeln(sprintf('<info>Dry run: %s</info>%s', $dryRun ? 'Yes' : 'No', PHP_EOL));

        $startTime = microtime(true);

        try {
            $this->platform = new Platform();
            $this->validateTools($output);
        } catch (Throwable $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');

            return self::FAILURE;
        }

        $initTime = microtime(true);
        $output->writeln(sprintf('<info>Init time: %.3fs</info>', $initTime - $startTime));
        $output->writeln('');

        [$jpegsToRemove, $arwsToRemove, $arwWarnings, $stats] = $this->gatherFiles($directories, $output);

        $gatherTime = microtime(true);
        $output->writeln(sprintf('<info>Gather time: %.3fs</info>', $gatherTime - $initTime));

        foreach ($arwWarnings as $dir => $files) {
            $output->writeln(sprintf(
                '<comment>Warning: %d ARWs in %s are not in any archive</comment>',
                count($files),
                $this->cliHelper->link($dir),
            ));
        }

        $jpegSpaceToFree = 0;
        foreach ($jpegsToRemove as $path) {
            $jpegSpaceToFree += (int) filesize($path);
        }

        $arwSpaceToFree = 0;
        foreach ($arwsToRemove as $path) {
            $arwSpaceToFree += (int) filesize($path);
        }

        if ($dryRun) {
            $output->writeln('');
            $output->writeln(sprintf(
                "JPEG Summary:\n  Found: %d\n  Skipped: %d\n  To remove: %d\n  Space to free: %s",
                $stats['jpegsFound'],
                $stats['jpegsSkipped'],
                count($jpegsToRemove),
                $this->formatBytes($jpegSpaceToFree),
            ));
            $output->writeln(sprintf(
                "\nARW Summary:\n  Found: %d\n  Skipped: %d\n  Not archived: %d\n  To remove: %d\n  Space to free: %s",
                $stats['arwsFound'],
                $stats['arwsSkipped'],
                $stats['arwsNotArchived'],
                count($arwsToRemove),
                $this->formatBytes($arwSpaceToFree),
            ));

            return self::SUCCESS;
        }

        $jpegsRemoved   = 0;
        $jpegSpaceFreed = 0;
        $jpegsErrored   = 0;

        foreach ($jpegsToRemove as $jpegPath) {
            try {
                $size = (int) filesize($jpegPath);
                unlink($jpegPath);
                $jpegsRemoved++;
                $jpegSpaceFreed += $size;

                $this->logger->info('Removed JPEG', ['file' => $jpegPath, 'size' => $size]);
            } catch (Throwable $exception) {
                $output->writeln(sprintf('<error>Failed to remove %s: %s</error>', $jpegPath, $exception->getMessage()));
                $jpegsErrored++;
            }
        }

        $arwsRemoved   = 0;
        $arwSpaceFreed = 0;
        $arwsErrored   = 0;

        foreach ($arwsToRemove as $arwPath) {
            try {
                $size = (int) filesize($arwPath);
                unlink($arwPath);
                $arwsRemoved++;
                $arwSpaceFreed += $size;

                $this->logger->info('Removed ARW', ['file' => $arwPath, 'size' => $size]);
            } catch (Throwable $exception) {
                $output->writeln(sprintf('<error>Failed to remove %s: %s</error>', $arwPath, $exception->getMessage()));
                $arwsErrored++;
            }
        }

        $endTime = microtime(true);

        $output->writeln('');
        $output->writeln(sprintf(
            "JPEG Summary:\n  Found: %d\n  Skipped: %d\n  Removed: %d\n  Errored: %d\n  Space freed: %s",
            $stats['jpegsFound'],
            $stats['jpegsSkipped'],
            $jpegsRemoved,
            $jpegsErrored,
            $this->formatBytes($jpegSpaceFreed),
        ));
        $output->writeln(sprintf(
            "\nARW Summary:\n  Found: %d\n  Skipped: %d\n  Not archived: %d\n  Removed: %d\n  Errored: %d\n  Space freed: %s",
            $stats['arwsFound'],
            $stats['arwsSkipped'],
            $stats['arwsNotArchived'],
            $arwsRemoved,
            $arwsErrored,
            $this->formatBytes($arwSpaceFreed),
        ));
        $output->writeln(sprintf(
            "\n<info>Timing:\n  Init: %.3fs\n  Gather: %.3fs\n  Remove: %.3fs\n  Total: %.3fs</info>",
            $initTime - $startTime,
            $gatherTime - $initTime,
            $endTime - $gatherTime,
            $endTime - $startTime,
        ));

        return self::SUCCESS;
    }

    private function validateTools(OutputInterface $output): void
    {
        $which = $this->platform->isWindows() ? 'where.exe' : 'which';

        $requiredTools = ['exiftool', 'tar'];

        foreach ($requiredTools as $tool) {
            $result = trim((string) shell_exec(sprintf('%s %s 2>/dev/null', $which, escapeshellarg($tool))));

            if ($this->platform->isWindows()) {
                $lines  = explode("\n", $result);
                $result = trim($lines[0]);
            }

            if ($result === '') {
                throw new RuntimeException(sprintf('Required tool not found: %s', $tool));
            }

            $this->toolPaths[$tool] = $result;
            $output->writeln(sprintf('<info>Found %s: %s</info>', $tool, $result));
        }
    }

    /**
     * @param list<string> $directories
     *
     * @return array{list<string>, list<string>, array<string, list<string>>, array{jpegsFound: int, jpegsSkipped: int, arwsFound: int, arwsSkipped: int, arwsNotArchived: int}}
     */
    private function gatherFiles(array $directories, OutputInterface $output): array
    {
        $jpegsToRemove = [];
        $arwsToRemove  = [];
        $arwWarnings   = [];
        $skipSet       = [];
        $stats         = [
            'jpegsFound' => 0,
            'jpegsSkipped' => 0,
            'arwsFound' => 0,
            'arwsSkipped' => 0,
            'arwsNotArchived' => 0,
        ];

        /** @var array<string, true> $processedDirs */
        $processedDirs = [];

        /** @var array<string, array<string, true>> $archivedFilesCache */
        $archivedFilesCache = [];

        foreach ($directories as $directory) {
            $directory = rtrim(trim($directory, '"\''), DIRECTORY_SEPARATOR);
            $output->writeln(sprintf('Scanning directory: %s', $this->cliHelper->link($directory)));

            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(directory: $directory, flags: FilesystemIterator::SKIP_DOTS),
            );

            foreach ($files as $file) {
                assert($file instanceof SplFileInfo);

                if (! $file->isFile()) {
                    continue;
                }

                $filePath  = $file->getPathname();
                $extension = strtolower($file->getExtension());
                $dir       = dirname($filePath);

                if (! isset($processedDirs[$dir])) {
                    $processedDirs[$dir] = true;
                    $skipSet            += $this->batchExiftoolCheck($dir, $output);
                }

                if (! in_array($extension, ['jpg', 'jpeg', 'arw'], true)) {
                    continue;
                }

                if (in_array($extension, ['jpg', 'jpeg'], true)) {
                    $stats['jpegsFound']++;

                    if (isset($skipSet[$filePath])) {
                        $output->writeln(sprintf('  Skipping (Portrait/Live): %s', $this->cliHelper->link($filePath)));
                        $stats['jpegsSkipped']++;
                        continue;
                    }

                    $avifPath = $this->getAvifPath($filePath);
                    if (! file_exists($avifPath)) {
                        $output->writeln(sprintf('  Skipping (no AVIF): %s', $this->cliHelper->link($filePath)));
                        $stats['jpegsSkipped']++;
                        continue;
                    }

                    $jpegsToRemove[] = $filePath;
                    $output->writeln(sprintf('  Will remove: %s', $this->cliHelper->link($filePath)));
                    continue;
                }

                $stats['arwsFound']++;

                if (! isset($archivedFilesCache[$dir])) {
                    $archivedFilesCache[$dir] = $this->getArchivedFilesInDir($dir);
                }

                $filename = basename($filePath);
                if (! isset($archivedFilesCache[$dir][$filename])) {
                    $stats['arwsNotArchived']++;
                    if (! isset($arwWarnings[$dir])) {
                        $arwWarnings[$dir] = [];
                    }

                    $arwWarnings[$dir][] = $filePath;
                    continue;
                }

                $arwsToRemove[] = $filePath;
                $output->writeln(sprintf('  Will remove: %s', $this->cliHelper->link($filePath)));
            }
        }

        return [$jpegsToRemove, $arwsToRemove, $arwWarnings, $stats];
    }

    /** @return array<string, true> */
    private function batchExiftoolCheck(string $dir, OutputInterface $output): array
    {
        $cmd = sprintf(
            '%s -if %s -p %s -ext jpg -ext jpeg %s 2>/dev/null',
            escapeshellarg($this->toolPaths['exiftool']),
            escapeshellarg('$DepthMapData or $EmbeddedVideoFile'),
            escapeshellarg('$directory/$filename'),
            escapeshellarg($dir),
        );

        $result = [];
        exec($cmd, $result, $exitCode);

        // Exit code 0 means files matched the condition (have depth/video data)
        if ($exitCode !== 0 || $result === []) {
            return [];
        }

        $skipSet = [];
        foreach ($result as $filename) {
            $skipSet[trim($filename)] = true;
        }

        $output->writeln(sprintf('  Found %d Portrait/Live Photos in: %s', count($result), $dir));

        return $skipSet;
    }

    private function getAvifPath(string $jpegPath): string
    {
        return dirname($jpegPath) . DIRECTORY_SEPARATOR . pathinfo($jpegPath, PATHINFO_FILENAME) . '.avif';
    }

    /** @return array<string, true> */
    private function getArchivedFilesInDir(string $dir): array
    {
        $archives = glob($dir . DIRECTORY_SEPARATOR . 'raws-*.tar.xz');
        if ($archives === false || $archives === []) {
            return [];
        }

        $archivedFiles = [];
        foreach ($archives as $archive) {
            if (preg_match('/raws-\d+\.tar\.xz$/', $archive) !== 1) {
                continue;
            }

            $cmd = sprintf(
                '%s -tf %s 2>/dev/null',
                escapeshellarg($this->toolPaths['tar']),
                escapeshellarg($archive),
            );

            $result = [];
            exec($cmd, $result, $exitCode);

            if ($exitCode !== 0) {
                continue;
            }

            foreach ($result as $filename) {
                $archivedFiles[trim($filename)] = true;
            }
        }

        return $archivedFiles;
    }

    private function formatBytes(int $bytes): string
    {
        return sprintf('%s KB', number_format((int) ($bytes / 1024), thousands_separator: ' '));
    }
}
