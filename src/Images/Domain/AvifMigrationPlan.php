<?php

declare(strict_types=1);

namespace App\Images\Domain;

final readonly class AvifMigrationPlan
{
    /**
     * @param list<string> $allAvifs
     * @param list<string> $alreadyMigratedAvifs AVIFs that already have sibling .heic
     * @param list<string> $toMigrateAvifs       AVIFs missing sibling .heic
     */
    public function __construct(
        public array $allAvifs,
        public array $alreadyMigratedAvifs,
        public array $toMigrateAvifs,
    ) {
    }
}
