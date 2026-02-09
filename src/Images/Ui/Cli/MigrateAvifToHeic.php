<?php

declare(strict_types=1);

namespace App\Images\Ui\Cli;

use App\Images\Domain\AvifCodec;
use App\Images\Domain\AvifMigrationPlanner;
use App\Images\Domain\CalculationSkipReason;
use App\Images\Domain\Exiftool;
use App\Images\Domain\FfmpegImageNormalizer;
use App\Images\Domain\HeicCodec;
use App\Images\Domain\ImageOptimizer;
use App\Images\Domain\LibAvifTools;
use App\Images\Domain\Ssimulacra2;
use App\Images\Domain\StrictMetadataVerifier;
use App\Images\Ui\Cli\Parallel\ParallelAvifMigrationProcessor;
use App\Images\Ui\Cli\Parallel\ParallelConcurrency;
use App\Shared\Domain\FilesystemScanner;
use App\Shared\Domain\Platform;
use App\Shared\Ui\Cli\CliHelper;
use App\Shared\Ui\Cli\Timing;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

use function basename;
use function count;
use function dirname;
use function file_exists;
use function microtime;
use function pathinfo;
use function sprintf;

use const DIRECTORY_SEPARATOR;
use const PATHINFO_FILENAME;

#[AsCommand(name: 'images:migrate-avif-to-heic', description: 'Convert existing AVIFs to HEIC for OneDrive compatibility (SSIMULACRA2 ≥ 90)')]
final class MigrateAvifToHeic extends Command
{
    public function __construct(
        private readonly CliHelper $cliHelper,
        private readonly FilesystemScanner $scanner,
        private readonly Timing $timing,
        private readonly Platform $platform,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    /** @param list<string> $directories */
    public function __invoke(
        OutputInterface $output,
        #[Option]
        bool $dryRun = false,
        #[Option(description: 'Enable worker pool for AVIF migration processing')]
        bool $parallel = false,
        #[Option(description: 'Parallel worker count (defaults to auto when --parallel is enabled)')]
        int|null $concurrency = null,
        #[Option(description: 'Recycle a worker after N AVIF jobs')]
        int $workerMaxJobs = 50,
        #[Option(description: 'Job inactivity timeout in seconds (0 disables)')]
        int $jobTimeout = 3600,
        #[Argument]
        array $directories = [],
    ): int {
        $startTime = $this->cliHelper->startCommand($output, $dryRun, $this->timing);

        if ($concurrency !== null && $concurrency <= 0) {
            $output->writeln('<error>Invalid --concurrency: must be a positive integer.</error>');

            return self::FAILURE;
        }

        if ($workerMaxJobs <= 0) {
            $output->writeln('<error>Invalid --worker-max-jobs: must be a positive integer.</error>');

            return self::FAILURE;
        }

        if ($jobTimeout < 0) {
            $output->writeln('<error>Invalid --job-timeout: must be zero or a positive integer.</error>');

            return self::FAILURE;
        }

        try {
            $exiftool   = new Exiftool($this->platform);
            $ssim       = Ssimulacra2::fromPlatform($this->platform);
            $normalizer = new FfmpegImageNormalizer($this->platform, $exiftool);
            $verifier   = new StrictMetadataVerifier();
            $optimizer  = new ImageOptimizer($ssim, $normalizer, $exiftool, $verifier);
            $avifCodec  = new AvifCodec(LibAvifTools::fromPlatform($this->platform));
            $heicCodec  = new HeicCodec($this->platform);
        } catch (Throwable $exception) {
            $output->writeln(sprintf('<error>%s</error>', $exception->getMessage()));

            return self::FAILURE;
        }

        $plan = (new AvifMigrationPlanner($this->scanner))->plan($directories);

        $output->writeln(sprintf(
            '<info>Found %d AVIFs (%d already have .heic, %d to migrate)</info>',
            count($plan->allAvifs),
            count($plan->alreadyMigratedAvifs),
            count($plan->toMigrateAvifs),
        ));
        $output->writeln(sprintf('<info>Available cores: %d</info>', $this->platform->nCores()));

        $effectiveConcurrency = null;
        if ($parallel) {
            $effectiveConcurrency = $concurrency ?? ParallelConcurrency::defaultFromCores($this->platform->nCores());

            $output->writeln(sprintf(
                '<info>Parallel AVIF migration mode: enabled (workers=%d, worker-max-jobs=%d, job-timeout=%ds)</info>',
                $effectiveConcurrency,
                $workerMaxJobs,
                $jobTimeout,
            ));
        } else {
            $output->writeln('<info>Parallel AVIF migration mode: disabled</info>');
        }

        $output->writeln('');

        if ($dryRun) {
            foreach ($plan->toMigrateAvifs as $path) {
                $output->writeln(sprintf('  Will process: %s', $this->cliHelper->link($path)));
            }

            return self::SUCCESS;
        }

        $result = $parallel
            ? $this->processAvifsParallel(
                $output,
                $plan->toMigrateAvifs,
                $effectiveConcurrency,
                $workerMaxJobs,
                $jobTimeout,
            )
            : $this->processAvifsSequential(
                $output,
                $plan->toMigrateAvifs,
                $optimizer,
                $avifCodec,
                $heicCodec,
            );

        $endTime = microtime(true);

        $totalDeltaBytes = $result->totalDeltaBytes();
        $totalSign       = $totalDeltaBytes >= 0 ? '+' : '-';
        $totalAbs        = $totalDeltaBytes >= 0 ? $totalDeltaBytes : -$totalDeltaBytes;

        $output->writeln(sprintf(
            "\nSummary:\n  Processed: %d\n  Skipped: %d\n  Errored: %d\n  Total Δ: %s%s\n  Time: %.1fs",
            $result->processedCount(),
            $result->skippedCount(),
            $result->erroredCount(),
            $totalSign,
            $this->cliHelper->formatBytes($totalAbs),
            $endTime - $startTime,
        ));

        return self::SUCCESS;
    }

    /** @param list<string> $avifPaths */
    private function processAvifsSequential(
        OutputInterface $output,
        array $avifPaths,
        ImageOptimizer $optimizer,
        AvifCodec $avifCodec,
        HeicCodec $heicCodec,
    ): AvifMigrationBatchResult {
        $result = new AvifMigrationBatchResult();

        $progressBar = $this->cliHelper->createProgressBar($output, count($avifPaths), 'AVIFs');
        $progressBar->start();

        $cliHelper           = $this->cliHelper;
        $statusEventCallback = static function (
            string $phase,
            int|null $quality,
            float|null $score,
            int|null $savedBytes,
            string|null $decision,
        ): void {
        };

        foreach ($avifPaths as $avifPath) {
            $fileName = basename($avifPath);
            $progressBar->setMessage(sprintf('%s | starting...', $fileName), 'status');
            $progressBar->display();

            $targetHeic = dirname($avifPath) . DIRECTORY_SEPARATOR . pathinfo($avifPath, PATHINFO_FILENAME) . '.heic';
            if (file_exists($targetHeic)) {
                $result->skipped[$avifPath] = 'target HEIC already exists';
                $progressBar->advance();
                continue;
            }

            $statusCallback = static function (int $q, float $score, int $saved) use ($progressBar, $fileName, $cliHelper, $result): void {
                $delta = -$saved; // current HEIC size minus original AVIF size
                $sign  = $delta >= 0 ? '+' : '-';
                $abs   = $delta >= 0 ? $delta : -$delta;

                $totalDelta = $result->totalDeltaBytes() + $delta;
                $totalSign  = $totalDelta >= 0 ? '+' : '-';
                $totalAbs   = $totalDelta >= 0 ? $totalDelta : -$totalDelta;

                $progressBar->setMessage(sprintf(
                    '%s | q=%d, score=%.1f, Δ=%s%s (total %s%s) | ok=%d skip=%d err=%d',
                    $fileName,
                    $q,
                    $score,
                    $sign,
                    $cliHelper->formatBytes($abs),
                    $totalSign,
                    $cliHelper->formatBytes($totalAbs),
                    $result->processedCount(),
                    $result->skippedCount(),
                    $result->erroredCount(),
                ), 'status');
                $progressBar->display();
            };

            try {
                $outcome = $optimizer->migrateAvifToHeic(
                    $avifPath,
                    $targetHeic,
                    $avifCodec,
                    $heicCodec,
                    $statusCallback,
                    $statusEventCallback,
                );
                if ($outcome instanceof CalculationSkipReason) {
                    $result->skipped[$avifPath] = $outcome->value;
                    $progressBar->advance();
                    continue;
                }

                $result->processed[$avifPath] = $outcome->optimizedSize - $outcome->originalSize;
            } catch (Throwable $exception) {
                $result->errored[$avifPath] = $exception->getMessage();
                $progressBar->clear();
                $output->writeln(sprintf('<error>%s: %s</error>', $fileName, $exception->getMessage()));
                $progressBar->display();
            }

            $progressBar->advance();
        }

        $progressBar->setMessage('Done', 'status');
        $progressBar->finish();
        $output->writeln('');

        return $result;
    }

    /** @param list<string> $avifPaths */
    private function processAvifsParallel(
        OutputInterface $output,
        array $avifPaths,
        int $concurrency,
        int $workerMaxJobs,
        int $jobTimeout,
    ): AvifMigrationBatchResult {
        $appPath = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'app.php';

        $processor = new ParallelAvifMigrationProcessor(
            $this->cliHelper,
            $this->logger,
            $appPath,
        );

        return $processor->process(
            $output,
            $avifPaths,
            $concurrency,
            $workerMaxJobs,
            $jobTimeout,
        );
    }
}
