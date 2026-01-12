<?php

declare(strict_types=1);

namespace App\Images\Ui\Cli;

use App\Images\Domain\AvifFilter;
use App\Images\Domain\Exiftool;
use App\Images\Domain\ImageFile;
use App\Images\Domain\ImageFileCollector;
use App\Images\Domain\ImageProcessingResult;
use App\Images\Domain\ImageTools;
use App\Shared\Domain\Platform;
use App\Shared\Ui\Cli\CliHelper;
use App\Shared\Ui\Cli\Timing;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

use function array_map;
use function basename;
use function count;
use function escapeshellarg;
use function exec;
use function file_exists;
use function file_put_contents;
use function filesize;
use function implode;
use function microtime;
use function rename;
use function sprintf;
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
        private readonly ImageFileCollector $collector,
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
        $totalJpegsSkipped   = 0;
        $totalJpegsErrored   = 0;
        $totalJpegSizeBefore = 0;
        $totalJpegSizeAfter  = 0;
        $totalArwsArchived   = 0;
        $totalArwSizeBefore  = 0;
        $totalArchiveSize    = 0;
        $totalQcTime         = 0.0;

        $collection = $this->collector->collectFromDirectories(
            $directories,
            $output,
            AvifFilter::OnlyWithout,
        );

        $gatherTime = microtime(true);
        $output->writeln(sprintf('<info>Gather time: %.3fs</info>', $gatherTime - $initTime));

        if ($dryRun) {
            $output->writeln(sprintf(
                "\n<info>Dry run complete. Found %d JPEGs to process, %d ARW directories to archive.</info>",
                count($collection->jpegs),
                count($collection->arwsByDir),
            ));

            return self::SUCCESS;
        }

        $jpegCount   = count($collection->jpegs);
        $progressBar = $this->cliHelper->createProgressBar($output, $jpegCount, 'JPEGs');
        $progressBar->start();

        $totalSavings = 0;

        $cliHelper = $this->cliHelper;

        foreach ($collection->jpegs as $imageFile) {
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
                    $progressBar->clear();
                    $output->write('<comment>Skipped: </comment>');
                    $output->writeln($this->cliHelper->link($imageFile->path));
                    $progressBar->display();
                    $progressBar->advance();
                    continue;
                }

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
        $arwDirCount      = count($collection->arwsByDir);

        if ($arwDirCount > 0) {
            $arwProgressBar = $this->cliHelper->createProgressBar($output, $arwDirCount, 'ARW dirs');
            $arwProgressBar->start();

            foreach ($collection->arwsByDir as $dir => $arwFiles) {
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
        new JpegSummary(
            found: $collection->stats->jpegsFound,
            processed: $totalJpegsProcessed,
            skipped: $collection->stats->jpegsSkipped + $totalJpegsSkipped,
            errored: $totalJpegsErrored,
            sizeBefore: $totalJpegSizeBefore,
            sizeAfter: $totalJpegSizeAfter,
        )->print($output, $this->cliHelper);

        $output->writeln('');
        new ArwSummary(
            found: $collection->stats->arwsFound,
            archived: $totalArwsArchived,
            sizeBefore: $totalArwSizeBefore,
            sizeAfter: $totalArchiveSize,
        )->print($output, $this->cliHelper);

        $output->writeln('');
        new Timing(
            total: $endTime - $startTime,
            init: $initTime - $startTime,
            gather: $gatherTime - $initTime,
            qc: $totalQcTime,
            archiving: $totalArchiveTime,
        )->print($output);

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

        $fileNames = array_map(basename(...), $arwFiles);
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
