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
use function preg_match;
use function preg_quote;
use function sprintf;
use function str_contains;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const JSON_THROW_ON_ERROR;

final class FfmpegEncoder implements Encoder
{
    private readonly string $ffprobePath;
    private readonly string $ffmpegPath;
    public EncoderName $activeEncoder;
    public readonly bool $hasTemporalAq;
    private readonly bool $hasNvencVbrHq;
    private readonly bool $hasNvencCq;
    private readonly bool $hasNvencRcLookahead;
    private readonly bool $hasNvencMultipass;
    private readonly bool $hasNvencBRefMode;
    private readonly bool $hasNvencBFrames;
    private readonly bool $hasApplePrioSpeed;
    private readonly bool $hasAppleRealtime;
    private readonly string|null $appleSpatialAqOption;
    private readonly bool $hasApplePowerEfficient;
    private readonly bool $hasAppleMaxRefFrames;
    public readonly bool $hasVmaf;

    public function __construct(
        bool $useCpu,
        private Platform $platform,
    ) {
        $this->ffprobePath = $this->platform->findTool('ffprobe');
        $this->ffmpegPath  = $this->platform->findTool('ffmpeg');

        $hasAppleToolbox = $this->platform->isDarwin() && $this->ffmpegHasEncoder('hevc_videotoolbox');
        $hasNvEncoder    = $this->ffmpegHasEncoder('hevc_nvenc');

        if ($useCpu) {
            $this->activeEncoder = EncoderName::Cpu;
        } else {
            if (! $hasAppleToolbox && ! $hasNvEncoder) {
                throw new RuntimeException('No hardware HEVC encoder found (neither Apple VideoToolbox nor NVIDIA NVENC)');
            }

            $this->activeEncoder = $hasAppleToolbox ? EncoderName::Apple : EncoderName::Nvidia;
        }

        $nvencHelp = $hasNvEncoder ? $this->ffmpegEncoderHelp('encoder=hevc_nvenc') : null;

        $this->hasTemporalAq       = $nvencHelp !== null && self::encoderHelpHasOption($nvencHelp, 'temporal-aq');
        $this->hasNvencVbrHq       = $nvencHelp !== null && self::encoderHelpHasValue($nvencHelp, 'vbr_hq');
        $this->hasNvencCq          = $nvencHelp !== null && self::encoderHelpHasOption($nvencHelp, 'cq');
        $this->hasNvencRcLookahead = $nvencHelp !== null && self::encoderHelpHasOption($nvencHelp, 'rc-lookahead');
        $this->hasNvencMultipass   = $nvencHelp !== null && self::encoderHelpHasOption($nvencHelp, 'multipass');
        $this->hasNvencBRefMode    = $nvencHelp !== null && self::encoderHelpHasOption($nvencHelp, 'b_ref_mode');
        $this->hasNvencBFrames     = $nvencHelp !== null && self::encoderHelpHasOption($nvencHelp, 'bf');

        $appleHelp = $hasAppleToolbox ? $this->ffmpegEncoderHelp('encoder=hevc_videotoolbox') : null;

        $this->hasApplePrioSpeed      = $appleHelp !== null && self::encoderHelpHasOption($appleHelp, 'prio_speed');
        $this->hasAppleRealtime       = $appleHelp !== null && self::encoderHelpHasOption($appleHelp, 'realtime');
        $this->appleSpatialAqOption   = self::detectAppleSpatialAqOption($appleHelp);
        $this->hasApplePowerEfficient = $appleHelp !== null && self::encoderHelpHasOption($appleHelp, 'power_efficient');
        $this->hasAppleMaxRefFrames   = $appleHelp !== null && self::encoderHelpHasOption($appleHelp, 'max_ref_frames');

        $this->hasVmaf = $this->ffmpegHasFilter('libvmaf');
    }

    private function encoderForFile(VideoFile $file): EncoderName
    {
        if ($file->hasRotation && $this->activeEncoder !== EncoderName::Cpu) {
            return EncoderName::Cpu;
        }

        return $this->activeEncoder;
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

    private function ffmpegEncoderHelp(string $target): string|null
    {
        $process = new Process([
            $this->ffmpegPath,
            '-hide_banner',
            '-h',
            $target,
        ]);
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        return $process->getOutput();
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

        return preg_match(
            '/^\s*[\.A-Z\| ]+\s+' . preg_quote($filter, '/') . '\s+/m',
            $process->getOutput(),
        ) === 1;
    }

    private static function encoderHelpHasOption(string $helpOutput, string $option): bool
    {
        return preg_match('/^\s*-' . preg_quote($option, '/') . '\b/m', $helpOutput) === 1;
    }

    private static function encoderHelpHasValue(string $helpOutput, string $value): bool
    {
        return preg_match('/\b' . preg_quote($value, '/') . '\b/m', $helpOutput) === 1;
    }

    private static function detectAppleSpatialAqOption(string|null $appleHelp): string|null
    {
        if ($appleHelp === null) {
            return null;
        }

        if (self::encoderHelpHasOption($appleHelp, 'spatial_aq')) {
            return 'spatial_aq';
        }

        if (self::encoderHelpHasOption($appleHelp, 'spatialaq')) {
            return 'spatialaq';
        }

        return null;
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
            '-show_streams',
            '-of',
            'json',
            $filePath,
        ]);
        // Cloud-backed files (for example OneDrive on-demand) can block probe reads for a while.
        $process->setTimeout(null);
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

        /** @var array{width?: int, height?: int, bit_rate?: int, pix_fmt?: string, codec_name?: string, color_space?: ?string, color_primaries?: ?string, color_transfer?: ?string, duration?: float, side_data_list?: ?list<array{side_data_type?: ?string}>} $stream */
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

        $hasRotation  = false;
        $sideDataList = $stream['side_data_list'] ?? [];
        foreach ($sideDataList as $sideData) {
            if (($sideData['side_data_type'] ?? null) === 'Display Matrix') {
                $hasRotation = true;
                break;
            }
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
            hasRotation      : $hasRotation,
        );
    }

    public function commandForFile(
        VideoFile $file,
        int $baseBitrate,
        float $maxBitrateSpike,
        string $tempFilePath,
    ): string {
        $encoder = $this->encoderForFile($file);

        $params = [
            escapeshellarg($this->ffmpegPath),
            '-hide_banner',
            '-loglevel error',
            '-progress',
            'pipe:1',
        ];

        if ($encoder === EncoderName::Nvidia) {
            $params = array_merge($params, [
                '-hwaccel cuda',
                '-hwaccel_output_format cuda',
            ]);
        }

        $params = array_merge($params, [
            '-fflags +genpts',
            '-i ' . escapeshellarg($file->path),
            '-c:a copy',
            '-c:v ' . $encoder->value,
            sprintf('-b:v %dk', $baseBitrate),
            '-tag:v hvc1',
            '-map_metadata 0',
            '-movflags +use_metadata_tags+faststart',
            '-y',
        ]);

        if (in_array($file->pixFmt, ['yuv420p', 'yuv420p10le'], true)) {
            if ($encoder !== EncoderName::Nvidia) {
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

        if ($encoder === EncoderName::Nvidia) {
            $params = array_merge($params, [
                sprintf('-maxrate:v %dk', $baseBitrate * $maxBitrateSpike),
                '-preset p7',
                $this->hasNvencVbrHq ? '-rc vbr_hq' : '-rc vbr',
                '-spatial_aq 1',
                '-aq-strength 12',
            ]);
            if ($this->hasNvencCq) {
                $params[] = '-cq 21';
            }

            if ($this->hasNvencRcLookahead) {
                $params[] = '-rc-lookahead 32';
            }

            if ($this->hasNvencMultipass) {
                $params[] = '-multipass fullres';
            }

            if ($this->hasTemporalAq) {
                $params[] = '-temporal-aq 1';
            }

            if ($this->hasNvencBFrames) {
                $params[] = '-bf 4';
            }

            if ($this->hasNvencBRefMode) {
                $params[] = '-b_ref_mode middle';
            }
        } elseif ($encoder === EncoderName::Apple) {
            $params = array_merge($params, [
                sprintf('-maxrate:v %dk', $baseBitrate * $maxBitrateSpike),
                '-quality quality',
            ]);

            if ($this->hasApplePrioSpeed) {
                $params[] = '-prio_speed 0';
            }

            if ($this->hasAppleRealtime) {
                $params[] = '-realtime 0';
            }

            if ($this->appleSpatialAqOption !== null) {
                $params[] = '-' . $this->appleSpatialAqOption . ' 1';
            }

            if ($this->hasApplePowerEfficient) {
                $params[] = '-power_efficient 0';
            }

            if ($this->hasAppleMaxRefFrames) {
                $params[] = '-max_ref_frames 4';
            }
        } elseif ($encoder === EncoderName::Cpu) {
            $params = array_merge($params, [
                '-preset medium',
                sprintf('-x265-params "pools=%s"', $this->platform->nCores()),
            ]);
        }

        // Preserve source timing/frame cadence (important for VFR clips).
        $params[] = '-fps_mode passthrough';

        $params[] = escapeshellarg($tempFilePath);

        return implode(' ', $params);
    }

    public function qualityScore(string $originalFilePath, string $processedFilePath): float
    {
        if (! $this->hasVmaf) {
            throw new RuntimeException('VMAF filter is not available in ffmpeg');
        }

        // windows ffmpeg vmaf does not support /dev/stdout
        $tempDir         = sys_get_temp_dir();
        $vmafLogFileName = 'vmaf_' . uniqid('', true) . '.json';
        $vmafLogFile     = $tempDir . '/' . $vmafLogFileName;

        $params = [
            $this->ffmpegPath,
            '-hide_banner',
            '-loglevel',
            'error',
            '-i',
            $originalFilePath,
            '-i',
            $processedFilePath,
            '-filter_complex',
            // Keep original as first input so ffmpeg applies source autorotation.
            // Align by decode order, not source timestamps/r_frame_rate, to avoid VFR drift.
            sprintf(
                '[0:v]settb=AVTB,setpts=N[reference];[1:v]settb=AVTB,setpts=N[distorted];[distorted][reference]libvmaf=log_path=%s:log_fmt=json:n_threads=%s:n_subsample=10',
                $vmafLogFileName,
                $this->platform->nCores(),
            ),
        ];

        $params[] = '-f';
        $params[] = 'null';
        $params[] = '-';

        $process = new Process($params);
        $process->setWorkingDirectory($tempDir);
        $process->setTimeout(null);
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
