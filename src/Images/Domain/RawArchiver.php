<?php

declare(strict_types=1);

namespace App\Images\Domain;

use App\Shared\Domain\Platform;
use Psr\Log\LoggerInterface;
use RuntimeException;

use function array_map;
use function basename;
use function count;
use function escapeshellarg;
use function exec;
use function file_exists;
use function file_put_contents;
use function filesize;
use function implode;
use function rename;
use function sprintf;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const DIRECTORY_SEPARATOR;

/**
 * Cross-platform: Windows uses two-step (tar → xz), Unix uses piped tar|xz.
 * Archive naming: raws-N.tar.xz where N is the file count.
 */
final readonly class RawArchiver
{
    public function __construct(
        private Platform $platform,
        private string $xzPath,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<string> $arwFiles Full paths to ARW files
     *
     * @return int Archive size in bytes
     *
     * @throws RuntimeException If archiving fails.
     */
    public function archive(string $directory, array $arwFiles): int
    {
        $count       = count($arwFiles);
        $archiveName = sprintf('raws-%d.tar.xz', $count);
        $archivePath = $directory . DIRECTORY_SEPARATOR . $archiveName;

        $runId    = uniqid('gallinor-', true);
        $tmpDir   = sys_get_temp_dir();
        $listFile = $tmpDir . DIRECTORY_SEPARATOR . $runId . '-arwlist.txt';

        $fileNames = array_map(basename(...), $arwFiles);
        file_put_contents($listFile, implode("\n", $fileNames));

        try {
            $archiveSize = $this->platform->isWindows()
                ? $this->archiveWindows($directory, $listFile, $archivePath, $runId, $tmpDir)
                : $this->archiveUnix($directory, $listFile, $archivePath);

            $this->logger->info('Archived ARWs', [
                'directory' => $directory,
                'file_count' => $count,
                'archive_path' => $archivePath,
            ]);

            return $archiveSize;
        } finally {
            $this->cleanup($listFile);
        }
    }

    /** @throws RuntimeException If tar or xz fails. */
    private function archiveWindows(string $directory, string $listFile, string $archivePath, string $runId, string $tmpDir): int
    {
        $tarPath = $tmpDir . DIRECTORY_SEPARATOR . $runId . '.tar';

        $tarCmd = sprintf(
            'tar -cf %s -C %s -T %s 2>&1',
            escapeshellarg($tarPath),
            escapeshellarg($directory),
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

        $xzCmd = sprintf('%s -9 -T0 %s 2>&1', escapeshellarg($this->xzPath), escapeshellarg($tarPath));

        $xzOutput = [];
        exec($xzCmd, $xzOutput, $xzExitCode);

        if ($xzExitCode !== 0) {
            $this->cleanup($tarPath);

            throw new RuntimeException(sprintf(
                "xz failed with exit code %d:\n%s",
                $xzExitCode,
                implode("\n", $xzOutput),
            ));
        }

        $compressedTar = $tarPath . '.xz';
        rename($compressedTar, $archivePath);

        return (int) filesize($archivePath);
    }

    /** @throws RuntimeException If piped tar|xz fails. */
    private function archiveUnix(string $directory, string $listFile, string $archivePath): int
    {
        $cmd = sprintf(
            'tar -cf - -C %s -T %s | %s -9 -T0 > %s 2>&1',
            escapeshellarg($directory),
            escapeshellarg($listFile),
            escapeshellarg($this->xzPath),
            escapeshellarg($archivePath),
        );

        $cmdOutput = [];
        exec($cmd, $cmdOutput, $cmdExitCode);

        if ($cmdExitCode !== 0) {
            $this->cleanup($archivePath);

            throw new RuntimeException(sprintf(
                "Archive creation failed with exit code %d:\n%s",
                $cmdExitCode,
                implode("\n", $cmdOutput),
            ));
        }

        return (int) filesize($archivePath);
    }

    private function cleanup(string ...$files): void
    {
        foreach ($files as $file) {
            if (! file_exists($file)) {
                continue;
            }

            unlink($file);
        }
    }
}
