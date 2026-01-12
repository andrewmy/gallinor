<?php

declare(strict_types=1);

namespace App\Images\Domain;

use function count;

/**
 * Result of archive verification - categorizes ARWs by archive status.
 */
final readonly class ArchiveVerificationResult
{
    /**
     * @param list<string>                $arwsToRemove
     * @param array<string, list<string>> $unarchivedArws
     * @param list<string>                $warnings
     */
    public function __construct(
        public array $arwsToRemove = [],
        public array $unarchivedArws = [],
        public array $warnings = [],
        public int $arwsFound = 0,
        public int $arwsSkipped = 0,
        public int $archiveReplacementSize = 0,
    ) {
    }

    /**
     * Returns a stats array compatible with existing ArwSummary usage.
     *
     * @return array{arwsFound: int, arwsSkipped: int, arwsNotArchived: int, archiveReplacementSize: int}
     */
    public function toStatsArray(): array
    {
        return [
            'arwsFound' => $this->arwsFound,
            'arwsSkipped' => $this->arwsSkipped,
            'arwsNotArchived' => $this->countNotArchived(),
            'archiveReplacementSize' => $this->archiveReplacementSize,
        ];
    }

    private function countNotArchived(): int
    {
        $count = 0;
        foreach ($this->unarchivedArws as $files) {
            $count += count($files);
        }

        return $count;
    }
}
