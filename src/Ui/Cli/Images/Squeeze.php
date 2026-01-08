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

use function array_any;
use function array_filter;
use function array_map;
use function assert;
use function basename;
use function ceil;
use function count;
use function dirname;
use function escapeshellarg;
use function exec;
use function explode;
use function file_exists;
use function file_put_contents;
use function filesize;
use function glob;
use function implode;
use function in_array;
use function microtime;
use function number_format;
use function pathinfo;
use function preg_match;
use function rename;
use function rtrim;
use function shell_exec;
use function sprintf;
use function strtolower;
use function sys_get_temp_dir;
use function trim;
use function uniqid;
use function unlink;

use const DIRECTORY_SEPARATOR;
use const PATHINFO_FILENAME;
use const PHP_EOL;

#[AsCommand(name: 'images:squeeze', description: 'Re-encode JPEGs to optimal AVIFs, XZ the ARWs')]
final class Squeeze extends Command
{
    private const float MIN_SSIM_SCORE = 85.0;
    private const int CQ_LEVEL_START   = 20;
    private const int CQ_LEVEL_END     = 2;
    private const int CQ_LEVEL_STEP    = 2;

    private Platform $platform;
    private string $runId;
    private string $tmpDir;

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
            $this->runId    = uniqid('gallinor-', true);
            $this->tmpDir   = sys_get_temp_dir();

            $this->validateTools($output);
        } catch (Throwable $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');

            return self::FAILURE;
        }

        $output->writeln(sprintf('<info>Available cores: %d</info>', $this->platform->nCores));
        $initTime = microtime(true);
        $output->writeln(sprintf('<info>Init time: %.3fs</info>', $initTime - $startTime));
        $output->writeln('');

        $totalJpegsProcessed = 0;
        $totalJpegsErrored   = 0;
        $totalJpegSizeBefore = 0;
        $totalJpegSizeAfter  = 0;
        $totalArwsArchived   = 0;
        $totalArchiveSize    = 0;
        $totalQcTime         = 0.0;

        [$jpegList, $arwsByDir, $gatherStats] = $this->gatherFiles($directories, $output);

        $totalJpegsFound   = $gatherStats['jpegsFound'];
        $totalJpegsSkipped = $gatherStats['jpegsSkipped'];
        $totalArwsFound    = $gatherStats['arwsFound'];

        $gatherTime = microtime(true);
        $output->writeln(sprintf('<info>Gather time: %.3fs</info>', $gatherTime - $initTime));

        if ($dryRun) {
            $output->writeln(sprintf(
                "\n<info>Dry run complete. Found %d JPEGs to process, %d ARW directories to archive.</info>",
                count($jpegList),
                count($arwsByDir),
            ));

            return self::SUCCESS;
        }

        $jpegCount = count($jpegList);
        $i         = 1;
        foreach ($jpegList as $jpegPath) {
            $output->writeln('');
            $output->writeln(sprintf(
                'Processing JPEG: %s (%d of %d)',
                $this->cliHelper->link($jpegPath),
                $i,
                $jpegCount,
            ));

            try {
                $originalSize         = (int) ceil(filesize($jpegPath) / 1024);
                $totalJpegSizeBefore += $originalSize;

                $result = $this->processJpeg($jpegPath, $output);

                if ($result === null) {
                    $totalJpegsSkipped++;
                    $output->writeln('<comment>Skipped (no suitable AVIF produced)</comment>');
                } else {
                    [$avifPath, $avifSizeKb, $qcTime, $finalCqLevel, $finalScore] = $result;
                    $totalJpegSizeAfter                                          += $avifSizeKb;
                    $totalQcTime                                                 += $qcTime;
                    $totalJpegsProcessed++;

                    $output->writeln(sprintf(
                        '<info>Saved: %s (cq-level=%d, score=%.2f, %s KB, saved %s KB)</info>',
                        $this->cliHelper->link($avifPath),
                        $finalCqLevel,
                        $finalScore,
                        number_format($avifSizeKb, thousands_separator: ' '),
                        number_format($originalSize - $avifSizeKb, thousands_separator: ' '),
                    ));

                    $this->logger->info('Processed JPEG', [
                        'original_file' => $jpegPath,
                        'original_size_kb' => $originalSize,
                        'avif_file' => $avifPath,
                        'avif_size_kb' => $avifSizeKb,
                        'cq_level' => $finalCqLevel,
                        'ssim_score' => $finalScore,
                    ]);
                }
            } catch (Throwable $exception) {
                $output->writeln(sprintf('<error>%s</error>', $exception->getMessage()));
                $totalJpegsErrored++;
            }

            $i++;
        }

        $output->writeln('');
        $output->writeln('<info>Archiving ARW files...</info>');
        $archiveStartTime = microtime(true);

        foreach ($arwsByDir as $dir => $arwFiles) {
            $output->writeln(sprintf('Directory: %s (%d files)', $this->cliHelper->link($dir), count($arwFiles)));

            try {
                $archiveSize        = $this->archiveArws($dir, $arwFiles, $output);
                $totalArchiveSize  += $archiveSize;
                $totalArwsArchived += count($arwFiles);
                $output->writeln(sprintf(
                    '<info>Archive created: %s KB</info>',
                    number_format($archiveSize, thousands_separator: ' '),
                ));
            } catch (Throwable $exception) {
                $output->writeln(sprintf('<error>%s</error>', $exception->getMessage()));
            }
        }

        $totalArchiveTime = microtime(true) - $archiveStartTime;

        $this->cleanup();

        $endTime = microtime(true);
        $output->writeln('');
        $output->writeln(sprintf(
            "JPEG Summary:\n  Found: %d\n  Processed: %d\n  Skipped: %d\n  Errored: %d\n  Size before: %s KB\n  Size after: %s KB\n  Savings: %s KB",
            $totalJpegsFound,
            $totalJpegsProcessed,
            $totalJpegsSkipped,
            $totalJpegsErrored,
            number_format($totalJpegSizeBefore, thousands_separator: ' '),
            number_format($totalJpegSizeAfter, thousands_separator: ' '),
            number_format($totalJpegSizeBefore - $totalJpegSizeAfter, thousands_separator: ' '),
        ));
        $output->writeln(sprintf(
            "\nARW Summary:\n  Found: %d\n  Archived: %d\n  Total archive size: %s KB",
            $totalArwsFound,
            $totalArwsArchived,
            number_format($totalArchiveSize, thousands_separator: ' '),
        ));
        $output->writeln(sprintf(
            "\n<info>Timing:\n  Init: %.3fs\n  Gather: %.3fs\n  JPEG QC: %.3fs\n  Archiving: %.3fs\n  Total: %.3fs</info>",
            $initTime - $startTime,
            $gatherTime - $initTime,
            $totalQcTime,
            $totalArchiveTime,
            $endTime - $startTime,
        ));

        return self::SUCCESS;
    }

    private function validateTools(OutputInterface $output): void
    {
        $which = $this->platform->isWindows() ? 'where.exe' : 'which';

        $requiredTools = ['exiftool', 'avifenc', 'avifdec', 'ssimulacra2', 'xz', 'tar'];

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
     * @return array{list<string>, array<string, list<string>>, array{jpegsFound: int, jpegsSkipped: int, arwsFound: int}}
     */
    private function gatherFiles(array $directories, OutputInterface $output): array
    {
        $jpegList  = [];
        $arwsByDir = [];
        $skipSet   = [];
        $stats     = ['jpegsFound' => 0, 'jpegsSkipped' => 0, 'arwsFound' => 0];

        /** @var array<string, true> $processedDirs */
        $processedDirs = [];

        foreach ($directories as $directory) {
            $directory = rtrim(trim($directory, '"\' '), DIRECTORY_SEPARATOR);
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
                    if (file_exists($avifPath)) {
                        $output->writeln(sprintf('  Skipping (AVIF exists): %s', $this->cliHelper->link($filePath)));
                        $stats['jpegsSkipped']++;
                        continue;
                    }

                    $jpegList[] = $filePath;
                    continue;
                }

                $stats['arwsFound']++;

                if (! isset($arwsByDir[$dir])) {
                    if ($this->archiveExistsInDir($dir)) {
                        $output->writeln(sprintf('  Skipping ARWs in dir (archive exists): %s', $this->cliHelper->link($dir)));
                        $arwsByDir[$dir] = []; // Mark as processed but empty
                        continue;
                    }

                    $arwsByDir[$dir] = [];
                }

                if ($arwsByDir[$dir] !== []) {
                    $arwsByDir[$dir][] = $filePath;
                } elseif (! $this->archiveExistsInDir($dir)) {
                    $arwsByDir[$dir][] = $filePath;
                }
            }
        }

        // Filter out empty dirs
        $arwsByDir = array_filter($arwsByDir, static fn (array $files) => $files !== []);

        return [$jpegList, $arwsByDir, $stats];
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

    private function archiveExistsInDir(string $dir): bool
    {
        $files = glob($dir . DIRECTORY_SEPARATOR . 'raws-*.tar.xz');

        if ($files === false) {
            return false;
        }

        return array_any($files, static fn (string $file): bool => preg_match('/raws-\d+\.tar\.xz$/', $file) === 1);
    }

    private function getAvifPath(string $jpegPath): string
    {
        return dirname($jpegPath) . DIRECTORY_SEPARATOR . pathinfo($jpegPath, PATHINFO_FILENAME) . '.avif';
    }

    /** @return array{string, int, float, int, float}|null [avifPath, sizeKb, qcTime, cqLevel, score] or null if skipped */
    private function processJpeg(string $jpegPath, OutputInterface $output): array|null
    {
        $tmpAvif = $this->tmpDir . DIRECTORY_SEPARATOR . $this->runId . '.avif';
        $tmpPng  = $this->tmpDir . DIRECTORY_SEPARATOR . $this->runId . '.png';

        $originalSizeKb = (int) ceil(filesize($jpegPath) / 1024);
        $totalQcTime    = 0.0;

        for ($cqLevel = self::CQ_LEVEL_START; $cqLevel >= self::CQ_LEVEL_END; $cqLevel -= self::CQ_LEVEL_STEP) {
            $encodeCmd = sprintf(
                '%s -s 6 -j 8 -y 420 -d 10 -a tune=iq -a end-usage=q -a cq-level=%d %s %s 2>&1',
                escapeshellarg($this->toolPaths['avifenc']),
                $cqLevel,
                escapeshellarg($jpegPath),
                escapeshellarg($tmpAvif),
            );

            exec($encodeCmd, $encodeOutput, $encodeExitCode);

            if ($encodeExitCode !== 0) {
                if (file_exists($tmpAvif)) {
                    unlink($tmpAvif);
                }

                throw new RuntimeException(sprintf('avifenc failed with exit code %d', $encodeExitCode));
            }

            // Decode AVIF to PNG for QC
            $decodeCmd = sprintf(
                '%s --png-compress 0 %s %s 2>&1',
                escapeshellarg($this->toolPaths['avifdec']),
                escapeshellarg($tmpAvif),
                escapeshellarg($tmpPng),
            );

            exec($decodeCmd, $decodeOutput, $decodeExitCode);

            if ($decodeExitCode !== 0) {
                if (file_exists($tmpAvif)) {
                    unlink($tmpAvif);
                }

                throw new RuntimeException(sprintf('avifdec failed with exit code %d', $decodeExitCode));
            }

            $qcStartTime = microtime(true);
            $scoreCmd    = sprintf(
                '%s %s %s 2>&1',
                escapeshellarg($this->toolPaths['ssimulacra2']),
                escapeshellarg($jpegPath),
                escapeshellarg($tmpPng),
            );

            $scoreOutput = [];
            exec($scoreCmd, $scoreOutput, $scoreExitCode);
            $totalQcTime += microtime(true) - $qcStartTime;

            if ($scoreExitCode !== 0) {
                if (file_exists($tmpAvif)) {
                    unlink($tmpAvif);
                }

                throw new RuntimeException(sprintf('ssimulacra2 failed with exit code %d', $scoreExitCode));
            }

            $score = (float) trim($scoreOutput[0] ?? '0');
            $output->writeln(sprintf('  cq-level=%d, score=%.2f', $cqLevel, $score));

            if ($score >= self::MIN_SSIM_SCORE) {
                $avifSizeKb = (int) ceil(filesize($tmpAvif) / 1024);

                if ($avifSizeKb >= $originalSizeKb) {
                    $output->writeln(sprintf(
                        '  <comment>AVIF not smaller (%s KB >= %s KB), skipping</comment>',
                        number_format($avifSizeKb, thousands_separator: ' '),
                        number_format($originalSizeKb, thousands_separator: ' '),
                    ));
                    unlink($tmpAvif);

                    return null;
                }

                $finalPath = $this->getAvifPath($jpegPath);
                rename($tmpAvif, $finalPath);

                return [$finalPath, $avifSizeKb, $totalQcTime, $cqLevel, $score];
            }

            if ($cqLevel <= self::CQ_LEVEL_END) {
                continue;
            }

            $output->writeln(sprintf('  Score %.2f < %.2f, trying higher quality...', $score, self::MIN_SSIM_SCORE));
            unlink($tmpAvif);
        }

        $output->writeln(sprintf('<comment>  Could not achieve score >= %.2f even at cq-level=%d</comment>', self::MIN_SSIM_SCORE, self::CQ_LEVEL_END));

        if (file_exists($tmpAvif)) {
            unlink($tmpAvif);
        }

        return null;
    }

    /**
     * @param list<string> $arwFiles
     *
     * @return int Archive size in KB
     */
    private function archiveArws(string $dir, array $arwFiles, OutputInterface $output): int
    {
        $count       = count($arwFiles);
        $archiveName = sprintf('raws-%d.tar.xz', $count);
        $archivePath = $dir . DIRECTORY_SEPARATOR . $archiveName;
        $listFile    = $this->tmpDir . DIRECTORY_SEPARATOR . $this->runId . '-arwlist.txt';

        $fileNames = array_map(static fn (string $path) => basename($path), $arwFiles);
        file_put_contents($listFile, implode("\n", $fileNames));

        try {
            if ($this->platform->isWindows()) {
                // two-step process
                $tarPath = $this->tmpDir . DIRECTORY_SEPARATOR . $this->runId . '.tar';

                $tarCmd = sprintf(
                    'tar -cf %s -C %s -T %s 2>&1',
                    escapeshellarg($tarPath),
                    escapeshellarg($dir),
                    escapeshellarg($listFile),
                );

                exec($tarCmd, $tarOutput, $tarExitCode);

                if ($tarExitCode !== 0) {
                    throw new RuntimeException(sprintf('tar failed with exit code %d', $tarExitCode));
                }

                $xzCmd = sprintf('%s -9 -T0 %s 2>&1', escapeshellarg($this->toolPaths['xz']), escapeshellarg($tarPath));
                exec($xzCmd, $xzOutput, $xzExitCode);

                if ($xzExitCode !== 0) {
                    if (file_exists($tarPath)) {
                        unlink($tarPath);
                    }

                    throw new RuntimeException(sprintf('xz failed with exit code %d', $xzExitCode));
                }

                $compressedTar = $tarPath . '.xz';
                rename($compressedTar, $archivePath);
            } else {
                // yay pipe
                $cmd = sprintf(
                    'tar -cf - -C %s -T %s | %s -9 -T0 > %s 2>&1',
                    escapeshellarg($dir),
                    escapeshellarg($listFile),
                    escapeshellarg($this->toolPaths['xz']),
                    escapeshellarg($archivePath),
                );

                exec($cmd, $cmdOutput, $cmdExitCode);

                if ($cmdExitCode !== 0) {
                    if (file_exists($archivePath)) {
                        unlink($archivePath);
                    }

                    throw new RuntimeException(sprintf('Archive creation failed with exit code %d', $cmdExitCode));
                }
            }

            $this->logger->info('Archived ARWs', [
                'directory' => $dir,
                'file_count' => $count,
                'archive_path' => $archivePath,
            ]);

            return (int) ceil(filesize($archivePath) / 1024);
        } finally {
            if (file_exists($listFile)) {
                unlink($listFile);
            }
        }
    }

    private function cleanup(): void
    {
        $filesToClean = [
            $this->tmpDir . DIRECTORY_SEPARATOR . $this->runId . '.avif',
            $this->tmpDir . DIRECTORY_SEPARATOR . $this->runId . '.png',
            $this->tmpDir . DIRECTORY_SEPARATOR . $this->runId . '.tar',
            $this->tmpDir . DIRECTORY_SEPARATOR . $this->runId . '-arwlist.txt',
        ];

        foreach ($filesToClean as $file) {
            if (! file_exists($file)) {
                continue;
            }

            unlink($file);
        }
    }
}
