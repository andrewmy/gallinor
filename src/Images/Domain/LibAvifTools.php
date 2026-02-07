<?php

declare(strict_types=1);

namespace App\Images\Domain;

use App\Shared\Domain\Platform;
use RuntimeException;
use Symfony\Component\Process\Process;

final readonly class LibAvifTools
{
    public function __construct(
        public string $avifencPath,
        public string $avifdecPath,
    ) {
    }

    public static function fromPlatform(Platform $platform): self
    {
        return new self(
            avifencPath: $platform->findTool('avifenc'),
            avifdecPath: $platform->findTool('avifdec'),
        );
    }

    /** @throws RuntimeException */
    public function encodePngToAvif(string $sourcePng, string $targetAvif, int $cqLevel): void
    {
        $process = new Process([
            $this->avifencPath,
            '-s',
            '6',
            '-j',
            '8',
            '-y',
            '420',
            '-d',
            '10',
            '-a',
            'tune=iq',
            '-a',
            'end-usage=q',
            '-a',
            'cq-level=' . $cqLevel,
            $sourcePng,
            $targetAvif,
        ]);
        $process->mustRun();
    }

    /** @throws RuntimeException */
    public function decodeAvifToPng(string $avifPath, string $pngPath): void
    {
        $process = new Process([
            $this->avifdecPath,
            '--png-compress',
            '0',
            $avifPath,
            $pngPath,
        ]);
        $process->mustRun();
    }
}
