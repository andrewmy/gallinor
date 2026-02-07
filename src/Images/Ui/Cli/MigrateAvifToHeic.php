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
use App\Shared\Domain\FilesystemScanner;
use App\Shared\Domain\Platform;
use App\Shared\Ui\Cli\CliHelper;
use App\Shared\Ui\Cli\Timing;
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
use const PHP_EOL;

#[AsCommand(name: 'images:migrate-avif-to-heic', description: 'Convert existing AVIFs to HEIC for OneDrive compatibility (SSIMULACRA2 ≥ 90)')]
final class MigrateAvifToHeic extends Command
{
    public function __construct(
        private readonly CliHelper $cliHelper,
        private readonly FilesystemScanner $scanner,
        private readonly Timing $timing,
        private readonly Platform $platform,
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
        $output->writeln(sprintf('<info>Init time: %s</info>%s', $this->timing->formatInit(), PHP_EOL));

        $startTime = microtime(true);

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
        $output->writeln('');

        if ($dryRun) {
            foreach ($plan->toMigrateAvifs as $path) {
                $output->writeln(sprintf('  Will process: %s', $this->cliHelper->link($path)));
            }

            return self::SUCCESS;
        }

        $progressBar = $this->cliHelper->createProgressBar($output, count($plan->toMigrateAvifs), 'AVIFs');
        $progressBar->start();

        $processed       = 0;
        $skipped         = 0;
        $errored         = 0;
        $totalDeltaBytes = 0;

        foreach ($plan->toMigrateAvifs as $avifPath) {
            $fileName = basename($avifPath);
            $progressBar->setMessage(sprintf('%s | starting...', $fileName), 'status');
            $progressBar->display();

            $targetHeic = dirname($avifPath) . DIRECTORY_SEPARATOR . pathinfo($avifPath, PATHINFO_FILENAME) . '.heic';
            if (file_exists($targetHeic)) {
                $skipped++;
                $progressBar->advance();
                continue;
            }

            $cliHelper      = $this->cliHelper;
            $statusCallback = static function (int $q, float $score, int $saved) use ($progressBar, $fileName, $cliHelper, &$totalDeltaBytes, &$processed, &$skipped, &$errored): void {
                $delta = -$saved; // current HEIC size minus original AVIF size
                $sign  = $delta >= 0 ? '+' : '-';
                $abs   = $delta >= 0 ? $delta : -$delta;

                $totalDelta = $totalDeltaBytes + $delta;
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
                    $processed,
                    $skipped,
                    $errored,
                ), 'status');
                $progressBar->display();
            };

            try {
                $outcome = $optimizer->migrateAvifToHeic($avifPath, $targetHeic, $avifCodec, $heicCodec, $statusCallback);
                if ($outcome instanceof CalculationSkipReason) {
                    $skipped++;
                    $progressBar->advance();
                    continue;
                }

                $processed++;
                $totalDeltaBytes += $outcome->optimizedSize - $outcome->originalSize;
            } catch (Throwable $exception) {
                $errored++;
                $progressBar->clear();
                $output->writeln(sprintf('<error>%s: %s</error>', $fileName, $exception->getMessage()));
                $progressBar->display();
            }

            $progressBar->advance();
        }

        $progressBar->setMessage('Done', 'status');
        $progressBar->finish();
        $output->writeln('');

        $endTime = microtime(true);

        $totalSign = $totalDeltaBytes >= 0 ? '+' : '-';
        $totalAbs  = $totalDeltaBytes >= 0 ? $totalDeltaBytes : -$totalDeltaBytes;

        $output->writeln(sprintf(
            "\nSummary:\n  Processed: %d\n  Skipped: %d\n  Errored: %d\n  Total Δ: %s%s\n  Time: %.1fs",
            $processed,
            $skipped,
            $errored,
            $totalSign,
            $this->cliHelper->formatBytes($totalAbs),
            $endTime - $startTime,
        ));

        return self::SUCCESS;
    }
}
