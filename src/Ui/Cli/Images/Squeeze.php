<?php

declare(strict_types=1);

namespace App\Ui\Cli\Images;

use App\Domain\Exiftool;
use App\Domain\FilesystemScanner;
use App\Domain\ImageFile;
use App\Domain\ImageProcessingResult;
use App\Domain\ImageTools;
use App\Domain\Platform;
use App\Ui\Cli\CliHelper;
use App\Ui\Cli\Summary\ArwSummary;
use App\Ui\Cli\Summary\JpegSummary;
use App\Ui\Cli\Summary\Timing;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

use function array_any;
use function array_filter;
use function array_map;
use function basename;
use function count;
use function dirname;
use function escapeshellarg;
use function exec;
use function file_exists;
use function file_put_contents;
use function filesize;
use function glob;
use function implode;
use function in_array;
use function microtime;
use function preg_match;
use function rename;
use function sprintf;
use function strtolower;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const DIRECTORY_SEPARATOR;
use const PHP_EOL;

#[AsCommand(name: 'images:squeeze', description: 'Re-encode JPEGs to optimal AVIFs, XZ the ARWs')]
final class Squeeze extends Command
{
    private const float MIN_SSIM_SCORE = 85.0;
    private const int CQ_LEVEL_START   = 20;
    private const int CQ_LEVEL_END     = 2;
    private const int CQ_LEVEL_STEP    = 2;

    private Platform $platform;
    private ImageTools $imageTools;
    private Exiftool $exiftool;
    private string $runId;
    private string $tmpDir;

    /** @var array<string, string> Tool paths for xz and tar (archiving) */
    private array $toolPaths = [];

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly CliHelper $cliHelper,
        private readonly FilesystemScanner $scanner,
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
            $this->platform   = new Platform();
            $this->runId      = uniqid('gallinor-', true);
            $this->tmpDir     = sys_get_temp_dir();
            $this->imageTools = new ImageTools($this->platform);

            $output->writeln(sprintf('<info>Found avifenc: %s</info>', $this->imageTools->avifencPath));
            $output->writeln(sprintf('<info>Found avifdec: %s</info>', $this->imageTools->avifdecPath));
            $output->writeln(sprintf('<info>Found ssimulacra2: %s</info>', $this->imageTools->ssimulacra2Path));

            $this->validateArchiveTools($output);
            $this->exiftool = new Exiftool($this->platform);
            $output->writeln(sprintf('<info>Found exiftool: %s</info>', $this->exiftool->path()));
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
        $totalArwSizeBefore  = 0;
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

        $jpegCount   = count($jpegList);
        $progressBar = $this->cliHelper->createProgressBar($output, $jpegCount, 'JPEGs');
        $progressBar->start();

        $totalSavings = 0;

        $cliHelper = $this->cliHelper;

        foreach ($jpegList as $imageFile) {
            $fileName = $imageFile->filename();
            $progressBar->setMessage($fileName, 'status');
            $progressBar->display();

            $statusCallback = static function (int $cqLevel, float $score, int $saved) use ($progressBar, $fileName, &$totalSavings, $cliHelper): void {
                $runningTotal = $totalSavings + $saved;
                $progressBar->setMessage(
                    sprintf('%s | cq=%d, score=%.1f, saved %s (total: %s)', $fileName, $cqLevel, $score, $cliHelper->formatBytes($saved), $cliHelper->formatBytes($runningTotal)),
                    'status',
                );
                $progressBar->display();
            };

            try {
                $totalJpegSizeBefore += $imageFile->size;

                $result = $this->processJpeg($imageFile, $output, $statusCallback);

                if ($result === null) {
                    $totalJpegsSkipped++;
                    $progressBar->setMessage(sprintf('%s | <comment>Skipped</comment> (total: %s)', $fileName, $cliHelper->formatBytes($totalSavings)), 'status');
                } else {
                    $totalJpegSizeAfter += $result->avifSize;
                    $totalQcTime        += $result->qcTime;
                    $totalJpegsProcessed++;

                    $savings       = $result->savings($imageFile->size);
                    $totalSavings += $savings;
                    $progressBar->setMessage(
                        sprintf('%s | cq=%d, score=%.1f, saved %s (total: %s)', $fileName, $result->cqLevel, $result->qualityScore, $cliHelper->formatBytes($savings), $cliHelper->formatBytes($totalSavings)),
                        'status',
                    );

                    $this->logger->info('Processed JPEG', [
                        'original_file' => $imageFile->path,
                        'original_size' => $imageFile->size,
                        'avif_file' => $imageFile->optimizedPath(),
                        'avif_size' => $result->avifSize,
                        'cq_level' => $result->cqLevel,
                        'quality_score' => $result->qualityScore,
                    ]);
                }
            } catch (Throwable $exception) {
                $progressBar->setMessage(sprintf('%s | <error>Error</error>', $fileName), 'status');
                $progressBar->clear();
                $output->writeln(sprintf('<error>%s: %s</error>', $fileName, $exception->getMessage()));
                $progressBar->display();

                $totalJpegsErrored++;
            }

            $progressBar->advance();
        }

        $progressBar->setMessage('Done', 'status');
        $progressBar->finish();
        $output->writeln('');

        $archiveStartTime = microtime(true);
        $arwDirCount      = count($arwsByDir);

        if ($arwDirCount > 0) {
            $arwProgressBar = $this->cliHelper->createProgressBar($output, $arwDirCount, 'ARW dirs');
            $arwProgressBar->start();

            foreach ($arwsByDir as $dir => $arwFiles) {
                $dirName   = basename($dir);
                $fileCount = count($arwFiles);
                $arwProgressBar->setMessage(sprintf('%s (%d files)', $dirName, $fileCount), 'status');
                $arwProgressBar->display();

                $arwDirSizeBefore = 0;
                foreach ($arwFiles as $arwFile) {
                    $arwDirSizeBefore += filesize($arwFile);
                }

                $totalArwSizeBefore += $arwDirSizeBefore;

                try {
                    $archiveSize        = $this->archiveArws($dir, $arwFiles, $output);
                    $totalArchiveSize  += $archiveSize;
                    $totalArwsArchived += $fileCount;

                    $arwProgressBar->setMessage(
                        sprintf('%s | %s', $dirName, $cliHelper->formatBytes($archiveSize)),
                        'status',
                    );
                } catch (Throwable $exception) {
                    $arwProgressBar->setMessage(sprintf('%s | <error>Error</error>', $dirName), 'status');
                    $arwProgressBar->clear();
                    $output->writeln(sprintf('<error>%s: %s</error>', $dirName, $exception->getMessage()));
                    $arwProgressBar->display();
                }

                $arwProgressBar->advance();
            }

            $arwProgressBar->setMessage('Done', 'status');
            $arwProgressBar->finish();
            $output->writeln('');
        }

        $totalArchiveTime = microtime(true) - $archiveStartTime;

        $this->cleanup();

        $endTime = microtime(true);

        $output->writeln('');
        (new JpegSummary(
            found: $totalJpegsFound,
            processed: $totalJpegsProcessed,
            skipped: $totalJpegsSkipped,
            errored: $totalJpegsErrored,
            sizeBefore: $totalJpegSizeBefore,
            sizeAfter: $totalJpegSizeAfter,
        ))->print($output, $this->cliHelper);

        $output->writeln('');
        (new ArwSummary(
            found: $totalArwsFound,
            archived: $totalArwsArchived,
            sizeBefore: $totalArwSizeBefore,
            sizeAfter: $totalArchiveSize,
        ))->print($output, $this->cliHelper);

        $output->writeln('');
        (new Timing(
            total: $endTime - $startTime,
            init: $initTime - $startTime,
            gather: $gatherTime - $initTime,
            qc: $totalQcTime,
            archiving: $totalArchiveTime,
        ))->print($output);

        return self::SUCCESS;
    }

    private function validateArchiveTools(OutputInterface $output): void
    {
        $requiredTools = ['xz', 'tar'];

        foreach ($requiredTools as $tool) {
            $this->toolPaths[$tool] = $this->platform->findTool($tool);
            $output->writeln(sprintf('<info>Found %s: %s</info>', $tool, $this->toolPaths[$tool]));
        }
    }

    /**
     * @param list<string> $directories
     *
     * @return array{list<ImageFile>, array<string, list<string>>, array{jpegsFound: int, jpegsSkipped: int, arwsFound: int}}
     */
    private function gatherFiles(array $directories, OutputInterface $output): array
    {
        $jpegList  = [];
        $arwsByDir = [];
        $skipSet   = [];
        $stats     = ['jpegsFound' => 0, 'jpegsSkipped' => 0, 'arwsFound' => 0];

        /** @var array<string, true> $processedDirs */
        $processedDirs = [];

        foreach ($this->scanner->scanDirectories($directories) as $file) {
            $filePath  = $file->getPathname();
            $extension = strtolower($file->getExtension());
            $dir       = dirname($filePath);

            if (! isset($processedDirs[$dir])) {
                $processedDirs[$dir] = true;
                $found               = $this->exiftool->findPortraitAndLivePhotos($dir);
                if ($found !== []) {
                    $output->writeln(sprintf('  Found %d Portrait/Live Photos in: %s', count($found), $dir));
                    $skipSet += $found;
                }
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

                $imageFile = new ImageFile($filePath, $file->getSize());

                if ($imageFile->hasOptimized()) {
                    $output->writeln(sprintf('  Skipping (AVIF exists): %s', $this->cliHelper->link($filePath)));
                    $stats['jpegsSkipped']++;
                    continue;
                }

                $jpegList[] = $imageFile;
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

        // Filter out empty dirs
        $arwsByDir = array_filter($arwsByDir, static fn (array $files) => $files !== []);

        return [$jpegList, $arwsByDir, $stats];
    }

    private function archiveExistsInDir(string $dir): bool
    {
        $files = glob($dir . DIRECTORY_SEPARATOR . 'raws-*.tar.xz');

        if ($files === false) {
            return false;
        }

        return array_any($files, static fn (string $file): bool => preg_match('/raws-\d+\.tar\.xz$/', $file) === 1);
    }

    /** @param callable(int, float, int): void $statusCallback Called with (cqLevel, score, saved) during quality search */
    private function processJpeg(ImageFile $file, OutputInterface $output, callable $statusCallback): ImageProcessingResult|null
    {
        $tmpAvif = $this->tmpDir . DIRECTORY_SEPARATOR . $this->runId . '.avif';
        $tmpPng  = $this->tmpDir . DIRECTORY_SEPARATOR . $this->runId . '.png';

        $totalQcTime = 0.0;

        for ($cqLevel = self::CQ_LEVEL_START; $cqLevel >= self::CQ_LEVEL_END; $cqLevel -= self::CQ_LEVEL_STEP) {
            $this->imageTools->encodeToAvif($file->path, $tmpAvif, $cqLevel);
            $this->imageTools->decodeAvifToPng($tmpAvif, $tmpPng);

            $qcStartTime  = microtime(true);
            $score        = $this->imageTools->ssimulacra2Score($file->path, $tmpPng);
            $totalQcTime += microtime(true) - $qcStartTime;

            $currentAvifSize = (int) filesize($tmpAvif);
            $statusCallback($cqLevel, $score, $file->size - $currentAvifSize);

            if ($output->isVerbose()) {
                $output->writeln(sprintf('  cq-level=%d, score=%.2f', $cqLevel, $score));
            }

            if ($score >= self::MIN_SSIM_SCORE) {
                if ($currentAvifSize >= $file->size) {
                    if ($output->isVerbose()) {
                        $output->writeln(sprintf(
                            '  <comment>AVIF not smaller (%s >= %s), skipping</comment>',
                            $this->cliHelper->formatBytes($currentAvifSize),
                            $this->cliHelper->formatBytes($file->size),
                        ));
                    }

                    unlink($tmpAvif);

                    return null;
                }

                rename($tmpAvif, $file->optimizedPath());

                return new ImageProcessingResult(
                    avifSize: $currentAvifSize,
                    cqLevel: $cqLevel,
                    qualityScore: $score,
                    qcTime: $totalQcTime,
                );
            }

            if ($cqLevel <= self::CQ_LEVEL_END) {
                continue;
            }

            if ($output->isVerbose()) {
                $output->writeln(sprintf('  Score %.2f < %.2f, trying higher quality...', $score, self::MIN_SSIM_SCORE));
            }

            unlink($tmpAvif);
        }

        if ($output->isVerbose()) {
            $output->writeln(sprintf('<comment>  Could not achieve score >= %.2f even at cq-level=%d</comment>', self::MIN_SSIM_SCORE, self::CQ_LEVEL_END));
        }

        if (file_exists($tmpAvif)) {
            unlink($tmpAvif);
        }

        return null;
    }

    /**
     * @param list<string> $arwFiles
     *
     * @return int Archive size in bytes
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

                $tarOutput = [];
                exec($tarCmd, $tarOutput, $tarExitCode);

                if ($tarExitCode !== 0) {
                    throw new RuntimeException(sprintf(
                        "tar failed with exit code %d:\n%s",
                        $tarExitCode,
                        implode("\n", $tarOutput),
                    ));
                }

                $xzCmd = sprintf('%s -9 -T0 %s 2>&1', escapeshellarg($this->toolPaths['xz']), escapeshellarg($tarPath));

                $xzOutput = [];
                exec($xzCmd, $xzOutput, $xzExitCode);

                if ($xzExitCode !== 0) {
                    if (file_exists($tarPath)) {
                        unlink($tarPath);
                    }

                    throw new RuntimeException(sprintf(
                        "xz failed with exit code %d:\n%s",
                        $xzExitCode,
                        implode("\n", $xzOutput),
                    ));
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

                $cmdOutput = [];
                exec($cmd, $cmdOutput, $cmdExitCode);

                if ($cmdExitCode !== 0) {
                    if (file_exists($archivePath)) {
                        unlink($archivePath);
                    }

                    throw new RuntimeException(sprintf(
                        "Archive creation failed with exit code %d:\n%s",
                        $cmdExitCode,
                        implode("\n", $cmdOutput),
                    ));
                }
            }

            $this->logger->info('Archived ARWs', [
                'directory' => $dir,
                'file_count' => $count,
                'archive_path' => $archivePath,
            ]);

            return (int) filesize($archivePath);
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
