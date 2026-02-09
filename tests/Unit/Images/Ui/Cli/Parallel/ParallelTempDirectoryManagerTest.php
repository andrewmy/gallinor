<?php

declare(strict_types=1);

namespace App\Tests\Unit\Images\Ui\Cli\Parallel;

use App\Images\Ui\Cli\Parallel\ParallelTempDirectoryManager;
use PHPUnit\Framework\TestCase;

use function file_put_contents;
use function is_dir;
use function sprintf;
use function time;
use function touch;
use function uniqid;

final class ParallelTempDirectoryManagerTest extends TestCase
{
    public function test_ensure_dir_creates_nested_directory(): void
    {
        $workerRoot = self::uniqueWorkerRoot('ensure');
        $nestedPath = $workerRoot . '/a/b/c';

        try {
            ParallelTempDirectoryManager::ensureDir($nestedPath);

            self::assertDirectoryExists($nestedPath);
        } finally {
            ParallelTempDirectoryManager::removeDir($workerRoot);
        }
    }

    public function test_remove_dir_removes_directory_tree(): void
    {
        $workerRoot = self::uniqueWorkerRoot('remove');
        $nestedPath = $workerRoot . '/x/y';

        ParallelTempDirectoryManager::ensureDir($nestedPath);
        file_put_contents($nestedPath . '/file.txt', 'payload');
        self::assertDirectoryExists($workerRoot);

        ParallelTempDirectoryManager::removeDir($workerRoot);

        self::assertDirectoryDoesNotExist($workerRoot);
    }

    public function test_prune_stale_workers_removes_only_old_entries(): void
    {
        $oldWorkerRoot   = self::uniqueWorkerRoot('stale-old');
        $freshWorkerRoot = self::uniqueWorkerRoot('stale-fresh');

        try {
            ParallelTempDirectoryManager::ensureDir($oldWorkerRoot);
            ParallelTempDirectoryManager::ensureDir($freshWorkerRoot);

            touch($oldWorkerRoot, time() - 3600);
            touch($freshWorkerRoot, time());

            ParallelTempDirectoryManager::pruneStaleWorkers(60);

            self::assertDirectoryDoesNotExist($oldWorkerRoot);
            self::assertDirectoryExists($freshWorkerRoot);
        } finally {
            if (is_dir($oldWorkerRoot)) {
                ParallelTempDirectoryManager::removeDir($oldWorkerRoot);
            }

            if (is_dir($freshWorkerRoot)) {
                ParallelTempDirectoryManager::removeDir($freshWorkerRoot);
            }
        }
    }

    private static function uniqueWorkerRoot(string $prefix): string
    {
        $workerId = sprintf(
            '%s-%s-%s',
            $prefix,
            'phpunit',
            uniqid('', true),
        );

        return ParallelTempDirectoryManager::workerRoot($workerId);
    }
}
