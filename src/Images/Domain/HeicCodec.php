<?php

declare(strict_types=1);

namespace App\Images\Domain;

use App\Shared\Domain\Platform;
use RuntimeException;
use Symfony\Component\Process\Process;

use function sprintf;

final readonly class HeicCodec implements ImageCodec
{
    private string $heifEncPath;
    private string $heifConvertPath;

    public function __construct(
        private Platform $platform,
        /** @var array<string, scalar> */
        private array $x265Params = [
            'aq-mode' => 2,
            'aq-strength' => 1.0,
        ],
    ) {
        $this->heifEncPath     = $this->platform->findTool('heif-enc');
        $this->heifConvertPath = $this->platform->findTool('heif-convert');
    }

    public function format(): ImageFormat
    {
        return ImageFormat::Heic;
    }

    public function qualityLabel(): string
    {
        return 'q';
    }

    /** @throws RuntimeException */
    public function encodeFromPng(string $sourcePng, string $targetPath, int $quality): void
    {
        $options = new HeicEncoderOptions($quality, $this->x265Params);

        $args = [
            $this->heifEncPath,
            '-q',
            (string) $options->quality,
        ];

        foreach ($options->x265Params as $name => $value) {
            $args[] = '-p';
            $args[] = sprintf('x265:%s=%s', $name, (string) $value);
        }

        $args[] = '-o';
        $args[] = $targetPath;
        $args[] = $sourcePng;

        $process = new Process($args);
        $this->mustRunWithoutTimeout($process);
    }

    /** @throws RuntimeException */
    public function decodeToPng(string $sourcePath, string $targetPng): void
    {
        $process = new Process([
            $this->heifConvertPath,
            $sourcePath,
            $targetPng,
        ]);
        $this->mustRunWithoutTimeout($process);
    }

    /** @throws RuntimeException */
    private function mustRunWithoutTimeout(Process $process): void
    {
        // Large HEIC frames can legitimately exceed Symfony's default 60s timeout.
        $process->setTimeout(null);
        $process->setIdleTimeout(null);
        $process->mustRun();
    }
}
