<?php

declare(strict_types=1);

namespace App\Video\Infrastructure;

use App\Shared\Domain\Platform;
use App\Video\Domain\Encoder;
use App\Video\Domain\EncoderName;
use App\Video\Domain\VideoFile;
use JsonException;
use RuntimeException;
use Symfony\Component\Process\Process;

use function array_merge;
use function assert;
use function escapeshellarg;
use function file_get_contents;
use function filesize;
use function implode;
use function in_array;
use function is_array;
use function is_file;
use function json_decode;
use function json_encode;
use function sprintf;
use function str_contains;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const JSON_THROW_ON_ERROR;

final readonly class FfmpegEncoder implements Encoder
{
    private string $ffprobePath;
    private string $ffmpegPath;
    public EncoderName $activeEncoder;
    public bool $hasTemporalAq;
    public bool $hasVmaf;

    public function __construct(
        bool $useCpu,
        private Platform $platform,
    ) {
        $this->ffprobePath = $this->platform->findTool('ffprobe');
        $this->ffmpegPath  = $this->platform->findTool('ffmpeg');

        $hasAppleToolbox = ! $this->platform->isWindows() && $this->ffmpegHasEncoder('hevc_videotoolbox');
        $hasNvEncoder    = $this->ffmpegHasEncoder('hevc_nvenc');

        if ($useCpu) {
            $this->activeEncoder = EncoderName::Cpu;
        } else {
            if (! $hasAppleToolbox && ! $hasNvEncoder) {
                throw new RuntimeException('No hardware HEVC encoder found (neither Apple VideoToolbox nor NVIDIA NVENC)');
            }

            $this->activeEncoder = $hasAppleToolbox ? EncoderName::Apple : EncoderName::Nvidia;
        }

        $this->hasTemporalAq = $hasNvEncoder && $this->ffmpegHasOption('encoder=hevc_nvenc', 'temporal');

        $this->hasVmaf = $this->ffmpegHasFilter('vmaf');
    }

    private function ffmpegHasEncoder(string $encoder): bool
    {
        $process = new Process([
            $this->ffmpegPath,
            '-hide_banner',
            '-encoders',
        ]);
        $process->run();

        return $process->isSuccessful() && str_contains($process->getOutput(), $encoder);
    }

    private function ffmpegHasOption(string $target, string $option): bool
    {
        $process = new Process([
            $this->ffmpegPath,
            '-h',
            $target,
        ]);
        $process->run();

        return $process->isSuccessful() && str_contains($process->getOutput(), $option);
    }

    private function ffmpegHasFilter(string $filter): bool
    {
        $process = new Process([
            $this->ffmpegPath,
            '-hide_banner',
            '-filters',
        ]);
        $process->run();

        return $process->isSuccessful() && str_contains($process->getOutput(), $filter);
    }

    /** @throws RuntimeException */
    public function videoFileFromPath(string $filePath): VideoFile
    {
        $process = new Process([
            $this->ffprobePath,
            '-v',
            'error',
            '-select_streams',
            'v:0',
            '-show_entries',
            'stream=width,height,bit_rate,pix_fmt,codec_name,color_space,color_primaries,color_transfer,duration',
            '-of',
            'json',
            $filePath,
        ]);
        $process->mustRun();

        $mediaInfoStr = $process->getOutput();

        try {
            $mediaInfo = json_decode($mediaInfoStr, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Failed to parse video info: ' . $exception->getMessage());
        }

        assert(is_array($mediaInfo));
        if (! is_array($mediaInfo['streams']) || ! isset($mediaInfo['streams'][0])) {
            throw new RuntimeException('No video stream found in file, skipping');
        }

        /** @var array{width?: int, height?: int, bit_rate?: int, pix_fmt?: string, codec_name?: string, color_space?: ?string, color_primaries?: ?string, color_transfer?: ?string, duration?: float} $stream */
        $stream = $mediaInfo['streams'][0];
        if (
            ! isset(
                $stream['width'],
                $stream['height'],
                $stream['bit_rate'],
                $stream['pix_fmt'],
                $stream['codec_name'],
                $stream['duration'],
            )
        ) {
            throw new RuntimeException('Not all required fields found in video stream, skipping. JSON: ' . json_encode($stream));
        }

        return new VideoFile(
            path             : $filePath,
            width            : (int) $stream['width'],
            height           : (int) $stream['height'],
            bitRate          : (int) $stream['bit_rate'],
            pixFmt           : $stream['pix_fmt'],
            codecName        : $stream['codec_name'],
            duration         : (float) $stream['duration'],
            currentSize      : (int) filesize($filePath),
            colorSpace       : $stream['color_space'] ?? null,
            colorPrimaries   : $stream['color_primaries'] ?? null,
            colorTransfer    : $stream['color_transfer'] ?? null,
        );
    }

    public function commandForFile(
        VideoFile $file,
        int $baseBitrate,
        float $maxBitrateSpike,
        string $tempFilePath,
    ): string {
        $params = [
            escapeshellarg($this->ffmpegPath),
            '-hide_banner',
            '-loglevel error',
            '-progress',
            'pipe:1',
        ];

        if ($this->activeEncoder === EncoderName::Nvidia) {
            $params = array_merge($params, [
                '-hwaccel cuda',
                '-hwaccel_output_format cuda',
            ]);
        }

        $params = array_merge($params, [
            '-fflags +genpts',
            '-i ' . escapeshellarg($file->path),
            '-c:a copy',
            '-c:v ' . $this->activeEncoder->value,
            sprintf('-b:v %dk', $baseBitrate),
            '-tag:v hvc1',
            '-map_metadata 0',
            '-movflags +use_metadata_tags',
            '-y',
        ]);

        if (in_array($file->pixFmt, ['yuv420p', 'yuv420p10le'], true)) {
            if ($this->activeEncoder !== EncoderName::Nvidia) {
                $params[] = '-pix_fmt yuv420p10le';
            }

            $params[] = '-profile:v main10';
        } else {
            $params[] = '-pix_fmt ' . escapeshellarg($file->pixFmt);
        }

        if ($file->colorSpace !== null) {
            $params[] = '-colorspace ' . escapeshellarg($file->colorSpace);
        }

        if ($file->colorPrimaries !== null) {
            $params[] = '-color_primaries ' . escapeshellarg($file->colorPrimaries);
        }

        if ($file->colorTransfer !== null) {
            $params[] = '-color_trc ' . escapeshellarg($file->colorTransfer);
        }

        if ($this->activeEncoder === EncoderName::Nvidia) {
            $params = array_merge($params, [
                sprintf('-maxrate:v %dk', $baseBitrate * $maxBitrateSpike),
                '-preset p7',
                '-rc vbr',
                '-spatial_aq 1',
                '-aq-strength 12',
            ]);
            if ($this->hasTemporalAq) {
                $params[] = '-temporal-aq 1';
            }
        } elseif ($this->activeEncoder === EncoderName::Apple) {
            $params[] = '-quality quality';
        } elseif ($this->activeEncoder === EncoderName::Cpu) {
            $params = array_merge($params, [
                '-preset medium',
                sprintf('-x265-params "pools=%s"', $this->platform->nCores),
            ]);
        }

        $params[] = escapeshellarg($tempFilePath);

        return implode(' ', $params);
    }

    public function qualityScore(string $originalFilePath, string $processedFilePath): float
    {
        if (! $this->hasVmaf) {
            throw new RuntimeException('VMAF filter is not available in ffmpeg');
        }

        $tempDir = sys_get_temp_dir();
        // Use system temp directory for VMAF log (windows ffmpeg vmaf does not support /dev/stdout)
        $vmafLogFileName = 'vmaf_' . uniqid('', true) . '.json';
        $vmafLogFile     = $tempDir . '/' . $vmafLogFileName;

        $process = new Process([
            $this->ffmpegPath,
            '-hide_banner',
            '-loglevel',
            'error',
            '-i',
            $processedFilePath,
            '-i',
            $originalFilePath,
            '-lavfi',
            sprintf('libvmaf=log_path=%s:log_fmt=json:n_threads=%s:n_subsample=10', $vmafLogFileName, $this->platform->nCores),
            '-f',
            'null',
            '-',
        ]);
        // Set working directory to temp dir so we can use relative filename (avoids Windows drive letter colon issues)
        $process->setWorkingDirectory($tempDir);
        $process->mustRun();

        if (! is_file($vmafLogFile)) {
            throw new RuntimeException('VMAF log file was not created');
        }

        try {
            $vmafResult = json_decode((string) file_get_contents($vmafLogFile), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Failed to parse VMAF output: ' . $exception->getMessage());
        } finally {
            @unlink($vmafLogFile);
        }

        assert(is_array($vmafResult) && is_array($vmafResult['pooled_metrics']) && is_array($vmafResult['pooled_metrics']['vmaf']));
        if (! isset($vmafResult['pooled_metrics']['vmaf']['harmonic_mean'])) {
            throw new RuntimeException('Invalid VMAF output format: no pooled_metrics found');
        }

        return (float) $vmafResult['pooled_metrics']['vmaf']['harmonic_mean'];
    }

    /** @throws RuntimeException if VMAF is not available. */
    public function describeCapabilities(callable $writer): void
    {
        if (! $this->hasVmaf) {
            throw new RuntimeException('VMAF is not available. Quality checking is required.');
        }

        $writer(sprintf('Using encoder: %s', $this->activeEncoder->value));

        if ($this->activeEncoder !== EncoderName::Nvidia || ! $this->hasTemporalAq) {
            return;
        }

        $writer('NVENC Temporal AQ: available');
    }
}
