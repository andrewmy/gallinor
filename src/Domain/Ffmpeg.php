<?php

declare(strict_types=1);

namespace App\Domain;

use JsonException;
use RuntimeException;
use Symfony\Component\Process\Process;

use function array_merge;
use function assert;
use function ceil;
use function escapeshellarg;
use function file_exists;
use function file_get_contents;
use function filesize;
use function implode;
use function in_array;
use function is_array;
use function is_file;
use function is_string;
use function json_decode;
use function sprintf;
use function trim;
use function unlink;

use const JSON_THROW_ON_ERROR;

final readonly class Ffmpeg
{
    private string $ffprobePath;
    private string $ffmpegPath;
    public VideoEncoder $activeEncoder;
    public bool $hasTemporalAq;
    public bool $hasVmaf;

    public function __construct(
        bool $useCpu,
        private Platform $platform,
    ) {
        $this->ffprobePath = $this->platform->findTool('ffprobe');
        $this->ffmpegPath  = $this->platform->findTool('ffmpeg');

        $hasAppleToolbox = $this->platform->isWindows()
            ? false
            : $this->ffmpegHasEncoder('hevc_videotoolbox');
        $hasNvEncoder    = $this->ffmpegHasEncoder('hevc_nvenc');

        if ($useCpu) {
            $this->activeEncoder = VideoEncoder::Cpu;
        } else {
            if (! $hasAppleToolbox && ! $hasNvEncoder) {
                throw new RuntimeException('No hardware HEVC encoder found (neither Apple VideoToolbox nor NVIDIA NVENC)');
            }

            $this->activeEncoder = $hasAppleToolbox ? VideoEncoder::Apple : VideoEncoder::Nvidia;
        }

        if ($hasNvEncoder) {
            $this->hasTemporalAq = $this->ffmpegHasOption('encoder=hevc_nvenc', 'temporal');
        } else {
            $this->hasTemporalAq = false;
        }

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

        if (! $process->isSuccessful()) {
            return false;
        }

        return str_contains($process->getOutput(), $encoder);
    }

    private function ffmpegHasOption(string $target, string $option): bool
    {
        $process = new Process([
            $this->ffmpegPath,
            '-h',
            $target,
        ]);
        $process->run();

        if (! $process->isSuccessful()) {
            return false;
        }

        return str_contains($process->getOutput(), $option);
    }

    private function ffmpegHasFilter(string $filter): bool
    {
        $process = new Process([
            $this->ffmpegPath,
            '-hide_banner',
            '-filters',
        ]);
        $process->run();

        if (! $process->isSuccessful()) {
            return false;
        }

        return str_contains($process->getOutput(), $filter);
    }

    /** @throws RuntimeException */
    public function videoFileFromPath(string $filePath): VideoFile
    {
        $process = new Process([
            $this->ffprobePath,
            '-v', 'error',
            '-select_streams', 'v:0',
            '-show_entries', 'stream=width,height,bit_rate,pix_fmt,codec_name,color_space,color_primaries,color_transfer,duration',
            '-of', 'json',
            $filePath,
        ]);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('Failed to get video info, skipping: ' . $process->getErrorOutput());
        }

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
            currentSizeKb    : (int) ceil(filesize($filePath) / 1024),
            colorSpace       : $stream['color_space'] ?? null,
            colorPrimaries   : $stream['color_primaries'] ?? null,
            colorTransfer    : $stream['color_transfer'] ?? null,
        );
    }

    public function commandForFile(
        VideoFile $file,
        int $baseBitrate,
        float $maxBitrateSpikes,
        string $tempFilePath,
    ): string {
        $params = [
            $this->ffmpegPath,
            '-hide_banner',
            '-loglevel error',
            '-stats',
        ];

        if ($this->activeEncoder === VideoEncoder::Nvidia) {
            $params = array_merge($params, [
                '-hwaccel cuda',
                '-hwaccel_output_format cuda',
            ]);
        }

        $params = array_merge($params, [
            '-fflags +genpts',
            sprintf('-i "%s"', $file->path),
            '-c:a copy',
            '-c:v ' . $this->activeEncoder->value,
            sprintf('-b:v %dk', $baseBitrate),
            '-tag:v hvc1',
            '-map_metadata 0',
            '-movflags +use_metadata_tags',
            '-y',
        ]);

        if (in_array($file->pixFmt, ['yuv420p', 'yuv420p10le'], true)) {
            if ($this->activeEncoder !== VideoEncoder::Nvidia) {
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

        if ($this->activeEncoder === VideoEncoder::Nvidia) {
            $params = array_merge($params, [
                sprintf('-maxrate:v %dk', $baseBitrate * $maxBitrateSpikes),
                '-preset p7',
                '-rc vbr',
                '-spatial_aq 1',
                '-aq-strength 12',
            ]);
            if ($this->hasTemporalAq) {
                $params[] = '-temporal-aq 1';
            }
        } elseif ($this->activeEncoder === VideoEncoder::Apple) {
            $params[] = '-quality quality';
        } elseif ($this->activeEncoder === VideoEncoder::Cpu) {
            $params = array_merge($params, [
                '-preset medium',
                sprintf('-x265-params "pools=%s"', $this->platform->nCores),
            ]);
        }

        $params[] = escapeshellarg($tempFilePath);

        return implode(' ', $params);
    }

    public function vmafScore(string $originalFilePath, string $processedFilePath): float
    {
        if (! $this->hasVmaf) {
            throw new RuntimeException('VMAF filter is not available in ffmpeg');
        }

        // windows ffmpeg vmaf does not support /dev/stdout, need to use a temp file instead
        $vmafLogFile = 'var/vmaf.json';

        $process = new Process([
            $this->ffmpegPath,
            '-hide_banner',
            '-loglevel', 'error',
            '-i', $processedFilePath,
            '-i', $originalFilePath,
            '-lavfi', sprintf('libvmaf=log_path=%s:log_fmt=json:n_threads=%s:n_subsample=10', $vmafLogFile, $this->platform->nCores),
            '-f', 'null',
            '-',
        ]);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('Failed to execute VMAF command: ' . $process->getErrorOutput());
        }

        if (! is_file($vmafLogFile)) {
            throw new RuntimeException('Failed to execute VMAF command');
        }

        try {
            $vmafResult = json_decode((string) file_get_contents($vmafLogFile), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Failed to parse VMAF output: ' . $exception->getMessage());
        }

        assert(is_array($vmafResult) && is_array($vmafResult['pooled_metrics']) && is_array($vmafResult['pooled_metrics']['vmaf']));
        if (! isset($vmafResult['pooled_metrics']['vmaf']['harmonic_mean'])) {
            throw new RuntimeException('Invalid VMAF output format: no pooled_metrics found');
        }

        return (float) $vmafResult['pooled_metrics']['vmaf']['harmonic_mean'];
    }
}
