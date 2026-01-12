<?php

declare(strict_types=1);

namespace App\Domain;

final readonly class ImageCollection
{
    /**
     * @param list<ImageFile>             $jpegs
     * @param array<string, list<string>> $arwsByDir
     * @param array<string, true>         $skipSet
     */
    public function __construct(
        public array $jpegs = [],
        public array $arwsByDir = [],
        public array $skipSet = [],
        public ImageCollectionStats $stats = new ImageCollectionStats(),
    ) {
    }
}
