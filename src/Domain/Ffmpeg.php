<?php

declare(strict_types=1);

namespace App\Domain;

use JsonException;
use RuntimeException;

use function array_merge;
use function escapeshellarg;
use function file_get_contents;
use function filesize;
use function implode;
use function in_array;
use function is_array;
use function is_file;
use function json_decode;
use function json_encode;
use function shell_exec;
use function sprintf;
use function trim;

use const DIRECTORY_SEPARATOR;
use const JSON_THROW_ON_ERROR;

final readonly class Ffmpeg
{
    private string $ffprobePath;
    private string $ffmpegPath;
    public VideoEncoder $activeEncoder;
    public bool $hasTemporalAq;
    public bool $hasVmaf;

    public function __construct(bool $useCpu, private Platform $platform)
    {
        $which = $this->platform->isWindows() ? 'where.exe' : 'which';
        $grep  = $this->platform->isWindows() ? 'findstr' : 'grep';

        $ffprobePath = shell_exec($which . ' ffprobe');
        $ffmpegPath  = shell_exec($which . ' ffmpeg');
        if (empty($ffprobePath) || empty($ffmpegPath)) {
            throw new RuntimeException('ffprobe or ffmpeg not found in system path');
        }

        if (is_array($ffprobePath)) {
            $ffprobePath = $ffprobePath[0];
        }

        if (is_array($ffmpegPath)) {
            $ffmpegPath = $ffmpegPath[0];
        }

        $this->ffprobePath = trim($ffprobePath);
        $this->ffmpegPath  = trim($ffmpegPath);

        $hasAppleToolbox = $this->platform->isWindows()
            ? false
            : shell_exec('ffmpeg -hide_banner -encoders | grep hevc_videotoolbox');
        $hasNvEncoder    = shell_exec('ffmpeg -hide_banner -encoders | ' . $grep . ' hevc_nvenc');

        if ($useCpu) {
            $this->activeEncoder = VideoEncoder::Cpu;
        } else {
            if (empty($hasAppleToolbox) && empty($hasNvEncoder)) {
                throw new RuntimeException('No hardware HEVC encoder found (neither Apple VideoToolbox nor NVIDIA NVENC)');
            }

            $this->activeEncoder = ! empty($hasAppleToolbox) ? VideoEncoder::Apple : VideoEncoder::Nvidia;
        }

        if ($hasNvEncoder) {
            $temporalAqCheck     = shell_exec('ffmpeg -h encoder=hevc_nvenc 2>&1 | ' . $grep . ' temporal');
            $this->hasTemporalAq = ! empty($temporalAqCheck);
        } else {
            $this->hasTemporalAq = false;
        }

        $vmafCheck     = shell_exec('ffmpeg -hide_banner -filters | ' . $grep . ' vmaf');
        $this->hasVmaf = ! empty($vmafCheck);
    }

    /** @throws RuntimeException */
    public function videoFileFromPath(string $filePath): VideoFile
    {
        $mediaInfoStr = shell_exec(sprintf(
            'ffprobe -v error -select_streams v:0 -show_entries stream=width,height,bit_rate,pix_fmt,codec_name,color_space,color_primaries,color_transfer,duration -of json "%s"',
            $filePath,
        ));
        if ($mediaInfoStr === null) {
            throw new RuntimeException('Failed to get video info, skipping');
        }

        try {
            $mediaInfo = json_decode($mediaInfoStr, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Failed to parse video info: ' . $exception->getMessage());
        }

        if (! isset($mediaInfo['streams'][0])) {
            throw new RuntimeException('No video stream found in file, skipping');
        }

        /** @var array{width: int, height: int, bit_rate: int, pix_fmt: string, codec_name: string, color_space: ?string, color_primaries: ?string, color_transfer: ?string, duration: float} $stream */
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
            currentSizeKb    : (int) (filesize($filePath) / 1024),
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
            'ffmpeg',
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
            //$params[] = '-pix_fmt yuv420p10le';
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

        // windows ffmpeg does not support /dev/stdout, need to use a temp file instead
        $vmafLogFile = 'var' . DIRECTORY_SEPARATOR . 'vmaf.json';
        $vmafCmd     = sprintf(
            'ffmpeg -hide_banner -loglevel error -i "%s" -i "%s" -lavfi "libvmaf=log_path=%s:log_fmt=json:n_threads=%s:n_subsample=10" -f null -',
            $processedFilePath,
            $originalFilePath,
            escapeshellarg($vmafLogFile),
            $this->platform->nCores,
        );

        shell_exec($vmafCmd);
        if (! is_file($vmafLogFile)) {
            throw new RuntimeException('Failed to execute VMAF command');
        }

        try {
            $vmafResult = json_decode(file_get_contents($vmafLogFile), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Failed to parse VMAF output: ' . $exception->getMessage());
        }

        if (! isset($vmafResult['pooled_metrics']['vmaf']['harmonic_mean'])) {
            throw new RuntimeException('Invalid VMAF output format: no pooled_metrics found');
        }

        return (float) $vmafResult['pooled_metrics']['vmaf']['harmonic_mean'];
    }
}
