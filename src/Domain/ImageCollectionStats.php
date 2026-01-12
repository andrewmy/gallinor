<?php

declare(strict_types=1);

namespace App\Domain;

final readonly class ImageCollectionStats
{
    public function __construct(
        public int $jpegsFound = 0,
        public int $jpegsSkipped = 0,
        public int $arwsFound = 0,
    ) {
    }

    public function addJpegsFound(): self
    {
        return new self(
            jpegsFound: $this->jpegsFound + 1,
            jpegsSkipped: $this->jpegsSkipped,
            arwsFound: $this->arwsFound,
        );
    }

    public function addJpegsSkipped(): self
    {
        return new self(
            jpegsFound: $this->jpegsFound,
            jpegsSkipped: $this->jpegsSkipped + 1,
            arwsFound: $this->arwsFound,
        );
    }

    public function addArwsFound(): self
    {
        return new self(
            jpegsFound: $this->jpegsFound,
            jpegsSkipped: $this->jpegsSkipped,
            arwsFound: $this->arwsFound + 1,
        );
    }
}
